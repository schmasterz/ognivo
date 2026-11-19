# Network Modes & Host IPAM Architecture

Ognivo provides ranged **IPv4** subnet management (**IPAM**) with two operational network modes for pools assigned to hypervisor nodes: **Routed** (`routed`) and **Manual** (`manual`).

> [!IMPORTANT]
> **IPv4 only in 1.0.** IPv6 pools, allocation and dual-stack guests are not supported yet and are planned for a future release. See [IP Version Support](#5-ip-version-support-ipv4-only-in-10) for exactly what is enforced.

---

## 1. Routed Network Mode (`routed`)

In `routed` mode, instances communicate using point-to-point Layer 3 routing over a designated parent interface on the hypervisor host (e.g., `eth0` or `bond0`).

### Instance Specification
- **Parent Interface (`parent_interface`):** Required for every non-manual pool (`routed`, `bridged`, `macvlan`), with no default value.
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
- **No Parent Interface:** `parent_interface` is not used for `manual` pools; it is stored as an empty string and the API returns `null`.
- **No Injected Routed NIC:** `InstanceSpecBuilder` does not inject a `routed` `eth0` device; interfaces are attached via profile or out-of-band tooling.
- **No Cloud-Init Netplan Override:** Static route injection is omitted from Cloud-Init.
- **Provisioning Status:** Instances transition to `Running` with `network_status: 'unverified'` unless an observed IP reported by the hypervisor agent matches the allocated IP.
- **Admin UI Indicator:** Pools in `manual` mode display an operator-managed networking badge in the Admin UI.

---

## 3. Allocation Cooldown Period

- **IP Cooling Period:** Released IP allocations enter a `cooling` state (`released_at`) for a pool-configured duration (`cooldown_seconds`) before returning to general circulation. During this quarantine window, the address cannot be immediately reassigned, preventing network disruption and traffic leakage from previous instances.
- **Expiration & Cleanup:** `GET /api/ips` no longer deletes rows. Expired `cooling` rows are removed only by the scheduled `ipam:prune-cooling` command (every minute); until pruned, an expired row is shown with status `'released'` and `cooldown_remaining_seconds` `0`, and `allocateIp` already treats it as reusable.
- **Allocation Ordering:** The allocations list is ordered by pool, then numerically by IPv4 address.

---

## 4. Preflight Pool Verification

Before putting an IP pool into production, administrators can trigger a preflight check (`POST /api/admin/ip-pools/{ipPool}/preflight`), which verifies:
1. **Hypervisor Connectivity:** Authenticated mTLS reachability to the assigned host node.
2. **Parent Interface Existence:** Confirms the pool's `parent_interface` exists on the remote host (`GET /1.0/networks`).
3. **Range & Gateway Consistency:** Validates that `range_start`, `range_end`, and `gateway` fall within the CIDR subnet boundaries.

> [!NOTE]
> Preflight checks do not inspect host kernel `sysctl` settings (`net.ipv4.ip_forward` or `proxy_arp`) as those are not exposed by the Incus REST API. Verify host kernel parameters directly using the commands in Section 1.

---

## 5. IP Version Support (IPv4 only in 1.0)

Ognivo 1.0 allocates and tracks **IPv4 addresses only**.

| Aspect | Behavior in 1.0 |
| :--- | :--- |
| Pool creation | `POST /api/admin/ip-pools` rejects an `ip_version` other than `4` with a validation error on `ip_version` (`messages.ip.ipv6_not_supported_v1`: "IPv6 is not supported in v1.0."). Omitting `ip_version` creates an IPv4 pool |
| Pool CIDR | Must be a valid **IPv4** CIDR with a prefix length between `/16` and `/32`. An IPv6 CIDR fails validation |
| Instance NIC | The routed NIC is configured with `ipv4.address` only. No IPv6 address is assigned or tracked by Ognivo |
| Host configuration | The required `sysctl` settings in Section 1 are IPv4 settings (`ip_forward`, `proxy_arp`). No IPv6 forwarding or NDP proxy setup is needed or managed |
| Quotas | The per-user IP quota (`max_ips`) counts IPv4 allocations |

> [!NOTE]
> Ognivo does not manage IPv6 for guests. Whatever a guest image or the underlying Incus network does on its own (for example, link-local IPv6 addresses) is outside Ognivo's control and is not shown in IPAM.

### Planned for a future release

IPv6 pool support is on the roadmap (see the README and `CHANGELOG.md`) but has no committed version. Until it ships, do not rely on the schema columns that hint at IPv6 support (for example `virtual_machines.ipv6_address`); they are unused groundwork and may change.
