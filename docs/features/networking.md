# Network Modes & Host IPAM Architecture

Ognivo provides ranged IPv4/IPv6 subnet management (**IPAM**) with two operational network modes for pools assigned to hypervisor nodes: **Routed** (`routed`) and **Manual** (`manual`).

---

## 1. Routed Network Mode (`routed`)

In `routed` mode, instances communicate using point-to-point Layer 3 routing over a designated parent interface on the hypervisor host (e.g., `eth0` or `bond0`).

### Instance Specification
- **NIC Device:** Incus `routed` NIC (`type: nic`, `nictype: routed`, `parent: <parent_interface>`, `ipv4.address: <assigned_ip>`).
- **Default Route:** Cloud-Init provisions a Netplan v2 configuration inside virtual machines with an on-link link-local default gateway:
  ```yaml
  routes:
    - to: default
      via: 169.254.0.1
      on-link: true
  ```
- **MAC Address:** Deterministic Locally Administered Unicast MAC address (`02:54:xx:xx:xx:xx`).

### Required Hypervisor Host Configuration
Because the Incus REST API cannot modify host kernel `sysctl` parameters, operators must enable IPv4 forwarding and Proxy ARP on each hypervisor host:

1. **IPv4 Packet Forwarding:**
   ```bash
   sudo sysctl -w net.ipv4.ip_forward=1
   echo "net.ipv4.ip_forward = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   ```

2. **Proxy ARP / Neighbor Proxy:**
   When routing IPs from a subnet reachable over an uplink interface (e.g., `eth0`), Proxy ARP allows the host kernel to answer ARP queries on behalf of the guest IP:
   ```bash
   sudo sysctl -w net.ipv4.conf.all.proxy_arp=1
   sudo sysctl -w net.ipv4.conf.eth0.proxy_arp=1
   echo "net.ipv4.conf.all.proxy_arp = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   echo "net.ipv4.conf.eth0.proxy_arp = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   ```

---

## 2. Manual Network Mode (`manual`)

In `manual` mode, guest networking is managed externally by the infrastructure operator (e.g., custom BGP daemon, Open vSwitch, OVN/SDN controller, or pre-configured host bridge).

### Behavior & Instance Specification
- **No Injected Routed NIC:** `InstanceSpecBuilder` does not inject a `routed` `eth0` device; interfaces are attached via profile or out-of-band tooling.
- **No Cloud-Init Netplan Override:** Static route injection is omitted from Cloud-Init.
- **Provisioning Status:** Instances transition to `Running` with `network_status: 'unverified'` unless an observed IP reported by the hypervisor agent matches the allocated IP.
- **Admin UI Indicator:** Pools in `manual` mode display an operator-managed networking badge in the Admin UI.

---

## 3. Floating IPs, Cooldowns & Reverse DNS (PTR)

- **Floating IP Allocation:** Tenants can claim standalone floating IPs (`POST /api/ips/floating`) within their quota limits and dynamically attach, detach, or reassign them across instances (`POST /api/ips/{id}/reassign`).
- **IP Cooling Period:** Released IP allocations can enter a `cooling` state (`released_at`) before returning to general circulation.
- **Reverse DNS (PTR):** Tenants can configure custom FQDN PTR records (`PUT /api/ips/{id}/ptr`) on their allocated public IP addresses.

---

## 4. Preflight Pool Verification

Before putting an IP pool into production, administrators can trigger a preflight check (`POST /api/admin/ip-pools/{ipPool}/preflight`), which verifies:
1. **Hypervisor Connectivity:** Authenticated mTLS reachability to the assigned host node.
2. **Parent Interface Existence:** Confirms the pool's `parent_interface` exists on the remote host (`GET /1.0/networks`).
3. **Range & Gateway Consistency:** Validates that `range_start`, `range_end`, and `gateway` fall within the CIDR subnet boundaries.

> [!NOTE]
> Preflight checks do not inspect host kernel `sysctl` settings (`net.ipv4.ip_forward` or `proxy_arp`) as those are not exposed by the Incus REST API. Verify host kernel parameters directly using the commands in Section 1.
