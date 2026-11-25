# Manual Incus Network Testing Runbook

Hands-on verification runbook for testing Ognivo's four network modes (**Routed**, **Bridged**, **Macvlan**, and **Manual**), IPAM cooldown, pool validation, preflight checks, and UI/API behavior against a **real Incus host**.

> [!NOTE]
> **Example Values vs. Your Lab:** Commands and addresses below use example placeholders (`192.168.50.0/24`, `192.168.50.1`, `<HOST_NIC>`, `<BRIDGE_NAME>`, `<PROJECT>`, `<INSTANCE>`) that you must adapt to your real test subnet, interface names, and host setup. Never deploy test pools with subnets already assigned to other machines on your network.

---

## 1. Purpose and Scope

This runbook verifies end-to-end hypervisor networking across both **KVM virtual machines** and **LXC containers** on a live Incus node:
- Pool creation, validation (`422` rules), and **Preflight Check** behavior.
- Instance spec generation (`devices.eth0` and `cloud-init.network-config`) on Incus.
- Guest OS IP configuration, default routing, DNS resolution, and reachability.
- Fail-fast handling for invalid or missing `parent_interface` configurations.
- Mutable `parent_interface` updates on pools with existing allocations.
- IP release, cooldown countdown, `ipam:prune-cooling` pruning, numeric ordering, search filters, and the `manual` mode **Operator-managed** badge.

**Incus Version Assumption:** Tested with the Incus version you run on your test host (Ognivo officially supports Incus 6.0+ LTS; record the output of `incus version` in your [Results Checklist](#11-results-checklist)).

---

## 2. Prerequisites

Ensure the following are ready before starting:
1. **Real Incus Host:** Linux server with `root` (or `sudo`) access and Incus installed and initialized (`incus version`, `incus network list`).
2. **Ognivo Stack Running:** Development (`make up-dev`) or staging stack up and healthy (`backend`, `queue-worker`, `scheduler`, `incus-events`, `reverb`, `frontend`, `webserver`), with network reachability to the Incus host on TCP `8443`.
   - Note: In production mode (`APP_ENV=production`), private RFC 1918 Incus endpoints require `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS=true` in `.env.production`.
3. **Accounts:**
   - **Administrator account** (e.g., `admin@ognivo.local` in dev) to onboard the node, create/edit pools, and run preflight.
   - **Tenant account** (e.g., `customer@ognivo.local` in dev) with enough quota (VMs, vCPU, RAM, Disk, IPs) to deploy test instances.
4. **OS Image:** An Ubuntu 24.04 cloud image (`ubuntu/24.04` from `https://images.linuxcontainers.org`, protocol `simplestreams`, OS type `linux`) enabled in **OS Images** and attached to the test compute node. See [OS Images](../features/images.md).
5. **Lab Subnet:** A LAN subnet (or isolated test VLAN/bridge) with free, unallocated IPv4 addresses and a known reachable gateway (examples below use `192.168.50.0/24` and gateway `192.168.50.1`).

> [!IMPORTANT]
> **Active Pool Selection Order:** When provisioning an instance on a compute node, Ognivo selects the **first active IP pool (lowest pool ID) on that node with free IPs**. During sequential tests on a single Incus node, edit and deactivate (`active = false`) or delete the previous test pool before provisioning instances for the next network mode.

---

## 3. Host Preparation

Run the commands in this section on the **Incus host** (commands marked `# Example — adapt` must match your host's interface names and lab addressing).

### 3.1 Inspect Host Interfaces and Incus Networks
```bash
# Identify physical NICs, bridges, bonds, and VLANs (confirm wired, not wireless)
ip -br link
ip -4 a

# Verify what Incus reports via /1.0/networks
incus network list --format table
incus network show <INTERFACE_OR_BRIDGE>   # Example — adapt <INTERFACE_OR_BRIDGE>
```

### 3.2 Prepare for Routed Mode (`routed`)
Enable IPv4 forwarding and Proxy ARP on the host kernel so the host can route `/32` guest addresses:
```bash
# Example — adapt <HOST_NIC> to your host uplink interface (e.g. eno1, enp1s0)
sudo sysctl -w net.ipv4.ip_forward=1
sudo sysctl -w net.ipv4.conf.all.proxy_arp=1
sudo sysctl -w net.ipv4.conf.<HOST_NIC>.proxy_arp=1
```

### 3.3 Prepare for Bridged Mode (`bridged`)
Choose **Option A** (LAN Linux bridge `br0`) or **Option B** (Incus managed bridge `incusbr0`):

#### Option A: Create an Unmanaged Linux Bridge (`br0`) via Netplan
> [!WARNING]
> Modifying your primary uplink interface over SSH can disconnect your session if misconfigured. Always test with `sudo netplan try` and ensure out-of-band console access is available.

Create `/etc/netplan/60-br0.yaml` (**example — adapt** interface name, host IP, gateway, and DNS to your lab):
```yaml
network:
  version: 2
  ethernets:
    <HOST_NIC>:
      dhcp4: false
  bridges:
    br0:
      interfaces: [<HOST_NIC>]
      addresses: [192.168.50.5/24]   # Example — adapt to your host's LAN IP
      routes:
        - to: default
          via: 192.168.50.1          # Example — adapt to your LAN gateway
      nameservers:
        addresses: [1.1.1.1, 8.8.8.8]
```
Apply and verify:
```bash
sudo chmod 600 /etc/netplan/60-br0.yaml
sudo netplan try
ip -br link show br0
bridge link
incus network list --format table
incus network show br0
```
Expected in `incus network list`: `br0` appears with `TYPE: bridge` and `MANAGED: NO`.

#### Option B: Use an Incus Managed Bridge (`incusbr0`)
If you prefer testing on an Incus-managed NAT bridge instead of a LAN bridge, inspect `incusbr0` (or create a dedicated test bridge) and disable DHCP so static Netplan IPs assigned by Ognivo do not collide with dnsmasq leases:
```bash
# Example — adapt subnet/name if you create a dedicated test bridge
incus network show incusbr0
incus network set incusbr0 ipv4.dhcp false
incus network show incusbr0
```
Use the `ipv4.address` of `incusbr0` (for example `10.10.10.1/24` -> CIDR `10.10.10.0/24`, gateway `10.10.10.1`) when creating the Ognivo pool.

### 3.4 Prepare for Macvlan Mode (`macvlan`)
Macvlan attaches guests directly to a wired physical NIC, bond, or VLAN interface:
```bash
# 1. Identify your wired physical NIC (do NOT use wl*/wlan* wireless interfaces)
ip -br link

# 2. Optional: confirm the interface is not wireless (should return no wireless entry for <HOST_NIC>)
iw dev 2>/dev/null || true

# 3. Verify Incus reports the interface as physical, bond, or vlan
incus network list --format table
incus network show <HOST_NIC>   # Example — adapt <HOST_NIC> (e.g. eno1, enp1s0, bond0)
```

---

## 4. Add the Host in Ognivo

Onboard your Incus host in **Compute Nodes** following [Host Onboarding & mTLS Certificates](../features/host-onboarding.md):
1. Expose the Incus API on the host (`incus config set core.https_address <NODE_IP>:8443`).
2. Generate a single-use trust token (`incus config trust add ognivo-panel`) and connect the node in **Compute Nodes -> Create Node -> Connect with token** (or use a pre-trusted certificate).
3. Click **Verify connection** and confirm state is `ok`.
4. Confirm `ubuntu/24.04` is enabled in **OS Images** and attached to the test node.

---

## 5. Mode 1: Routed (`routed`) Test Cases

| Test ID | Steps in Ognivo UI & Commands | Expected Results |
| :--- | :--- | :--- |
| **NET-R01** | **Create Routed Pool:** In **Networking & IPAM -> Create Subnet Pool**, select the test node, `Network Mode: Routed (L3 /32 Link-Local)`, `CIDR: 192.168.50.0/24` *(example)*, `Gateway` *(optional — IPAM reservation only; left blank by the UI, so `first_usable` defaults to `192.168.50.1`, or enter `192.168.50.1` to reserve it)*, `Host Parent Interface: <HOST_NIC>`, `Excluded Reserved IPs: 192.168.50.2, 192.168.50.250`, `Cooldown: 60`. Submit. | Pool is created (`201`). If `Gateway` is left empty, `first_usable` defaults to `192.168.50.1` and with `192.168.50.2, 192.168.50.250` excluded the first allocatable IP is `192.168.50.1`; if an optional reserved `Gateway: 192.168.50.1` is entered, `.1` is dynamically excluded and the first allocatable IP is `192.168.50.3`. |
| **NET-R02** | **Run Preflight Check:** Click the shield (**Preflight Check**) button on the routed pool card. | Overall status is **Preflight checks passed**. All checks are green (`PASS`): **5** checks when `gateway` is omitted (`Host reachable`, `Parent interface exists`, `Parent interface type matches mode`, `Pool range consistent`, `Host sysctl & proxy ARP`), or **6** checks (also including `Gateway configured`) when an optional `gateway` is configured. |
| **NET-R03** | **Provision Routed KVM VM:** Log in as tenant (or admin) and deploy a KVM **Virtual Machine** using `ubuntu/24.04` on the test node. Wait for `Running`. Verify on Incus host:<br>`incus list --project <PROJECT>`<br>`incus config show <VM_NAME> --project <PROJECT> --expanded` | VM reaches `Running`. Expanded config shows `devices.eth0` (`type: nic`, `nictype: routed`, `parent: <HOST_NIC>`, `ipv4.address: <IP>`, `hwaddr: 02:54:...`) and `config.cloud-init.network-config` matching the Routed VM Netplan YAML below. |
| **NET-R04** | **Verify Routed VM Guest Networking:** Inside the VM (`incus exec <VM_NAME> --project <PROJECT> -- ...` or Web Console):<br>`ip -4 a`<br>`ip route`<br>`ping -c3 169.254.0.1`<br>`ping -c3 1.1.1.1`<br>`resolvectl status` | `eth0` has `<IP>/32`; default route is `default via 169.254.0.1 dev eth0 onlink`; pings and DNS (`1.1.1.1`, `8.8.8.8`) succeed (when host `ip_forward` + `proxy_arp` and upstream routing are active). |
| **NET-R05** | **Provision & Verify Routed Container:** Deploy an LXC **Container** using `ubuntu/24.04` on the same pool. Inspect:<br>`incus config show <CT_NAME> --project <PROJECT> --expanded`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip -4 a`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip route`<br>`incus exec <CT_NAME> --project <PROJECT> -- ping -c3 1.1.1.1` | Container reaches `Running`. `devices.eth0` has `nictype: routed`, `parent: <HOST_NIC>`, `ipv4.address: <IP>`, `hwaddr: 02:54:...`. **`cloud-init.network-config` IS present** in `config` (configuring `eth0` directly without `match`/`set-name`, with `<IP>/32`, point-to-point route via `169.254.0.1`, and DNS nameservers). Guest has `<IP>/32` and `default via 169.254.0.1`; connectivity succeeds. |

**Expected `incus config show <VM_NAME> --project <PROJECT> --expanded` excerpt for Routed VM (`NET-R03`):**
```yaml
config:
  cloud-init.network-config: |
    network:
      version: 2
      ethernets:
        eth0:
          match:
            macaddress: "02:54:xx:xx:xx:xx"
          set-name: eth0
          addresses:
            - 192.168.50.3/32
          routes:
            - to: default
              via: 169.254.0.1
              on-link: true
          nameservers:
            addresses:
              - 1.1.1.1
              - 8.8.8.8
  limits.cpu: "2"
  limits.memory: 2048MB
devices:
  eth0:
    hwaddr: 02:54:xx:xx:xx:xx
    ipv4.address: 192.168.50.3
    nictype: routed
    parent: <HOST_NIC>
    type: nic
```
For the **Routed Container (`NET-R05`)**, `devices.eth0` has the same fields (`ipv4.address` included), and `cloud-init.network-config` is also injected in `config`, configuring `eth0` directly (without `match`/`set-name`) with `<IP>/32`, the point-to-point route via `169.254.0.1`, and the pool DNS nameservers.

*(Before proceeding to Mode 2, deactivate or delete the Routed test pool if staying on the same node.)*

---

## 6. Mode 2: Bridged (`bridged`) Test Cases

| Test ID | Steps in Ognivo UI & Commands | Expected Results |
| :--- | :--- | :--- |
| **NET-B01** | **Create Bridged Pool:** In **Networking & IPAM -> Create Subnet Pool**, select the test node, `Network Mode: Bridged (L2 Bridge / NAT Bridge)`, `CIDR: 192.168.50.0/24` *(example — adapt to your bridge subnet)*, `Gateway: 192.168.50.1`, `Host Parent Interface: <BRIDGE_NAME>` (e.g. `br0` or `incusbr0`), `Excluded Reserved IPs: 192.168.50.2, 192.168.50.250`, `Cooldown: 60`. Submit. | Pool is created (`201`). Mode badge shows on card; gateway and excluded IPs are excluded from allocation. |
| **NET-B02** | **Run Preflight Check:** Click **Preflight Check** on the bridged pool. | Overall status is **Preflight checks passed**. All **6** checks are green (`PASS`): `Host reachable`, `Parent interface exists`, `Parent interface type matches mode` (confirms Linux bridge or Incus managed bridge), `Pool range consistent`, `Gateway configured`, `Host sysctl & proxy ARP`. |
| **NET-B03** | **Provision Bridged KVM VM:** Deploy a KVM **Virtual Machine** using `ubuntu/24.04`. Wait for `Running`. Verify on Incus host:<br>`incus list --project <PROJECT>`<br>`incus config show <VM_NAME> --project <PROJECT> --expanded` | VM reaches `Running`. `devices.eth0` has `type: nic`, `nictype: bridged`, `parent: <BRIDGE_NAME>`, `hwaddr: 02:54:...` and **NO `ipv4.address`**. `config.cloud-init.network-config` matches the Bridged Netplan YAML below, and `image` alias resolves to `ubuntu/24.04/cloud`. |
| **NET-B04** | **Verify Bridged VM Guest Networking:** Inside the VM:<br>`ip -4 a`<br>`ip route`<br>`ping -c3 192.168.50.1`<br>`ping -c3 1.1.1.1`<br>`resolvectl status`<br>From the Incus host: `ping -c3 <VM_IP>` | `eth0` has `<VM_IP>/24`; default route is `default via 192.168.50.1 dev eth0`; guest pings gateway and `1.1.1.1`; DNS servers match pool; **host-to-guest ping succeeds** over the bridge. |
| **NET-B05** | **Provision & Verify Bridged Container:** Deploy an LXC **Container** using `ubuntu/24.04`. Verify:<br>`incus config show <CT_NAME> --project <PROJECT> --expanded`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip -4 a`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip route`<br>`incus exec <CT_NAME> --project <PROJECT> -- ping -c3 192.168.50.1`<br>`incus exec <CT_NAME> --project <PROJECT> -- ping -c3 1.1.1.1`<br>`incus exec <CT_NAME> --project <PROJECT> -- resolvectl status` | Container reaches `Running` with `network_status: observed`. `devices.eth0` has `nictype: bridged` and **NO `ipv4.address`**; `cloud-init.network-config` IS present with `<CT_IP>/24` and `via: 192.168.50.1`. Guest IP, route, pings, and DNS succeed. |

**Expected `incus config show <instance> --project <PROJECT> --expanded` excerpt for Bridged (both VM and Container):**
```yaml
config:
  cloud-init.network-config: |
    network:
      version: 2
      ethernets:
        eth0:
          match:
            macaddress: "02:54:xx:xx:xx:xx"
          set-name: eth0
          addresses:
            - 192.168.50.3/24
          routes:
            - to: default
              via: 192.168.50.1
          nameservers:
            addresses:
              - 1.1.1.1
              - 8.8.8.8
devices:
  eth0:
    hwaddr: 02:54:xx:xx:xx:xx
    nictype: bridged
    parent: <BRIDGE_NAME>
    type: nic
```
*(Notice `ipv4.address` is absent on `devices.eth0`.)*

*(Before proceeding to Mode 3, deactivate or delete the Bridged test pool if staying on the same node.)*

---

## 7. Mode 3: Macvlan (`macvlan`) Test Cases

| Test ID | Steps in Ognivo UI & Commands | Expected Results |
| :--- | :--- | :--- |
| **NET-M01** | **Create Macvlan Pool:** In **Networking & IPAM -> Create Subnet Pool**, select the test node, `Network Mode: Macvlan (L2 Direct Physical NIC)`, `CIDR: 192.168.50.0/24` *(example — adapt)*, `Gateway: 192.168.50.1`, `Host Parent Interface: <HOST_NIC>` (wired physical NIC, bond, or VLAN), `Excluded Reserved IPs: 192.168.50.2, 192.168.50.250`, `Cooldown: 60`. Submit. | Pool is created (`201`). |
| **NET-M02** | **Run Preflight Check:** Click **Preflight Check** on the macvlan pool. | Overall status is **Preflight checks passed**. All **6** checks are green (`PASS`): `Host reachable`, `Parent interface exists`, `Parent interface type matches mode` (confirms `physical`, `bond`, or `vlan`), `Pool range consistent`, `Gateway configured`, `Host sysctl & proxy ARP`. |
| **NET-M03** | **Provision Macvlan KVM VM:** Deploy a KVM **Virtual Machine** using `ubuntu/24.04`. Wait for `Running`. Inspect:<br>`incus list --project <PROJECT>`<br>`incus config show <VM_NAME> --project <PROJECT> --expanded` | VM reaches `Running`. `devices.eth0` has `type: nic`, `nictype: macvlan`, `parent: <HOST_NIC>`, `hwaddr: 02:54:...` and **NO `ipv4.address`**. `config.cloud-init.network-config` matches the Macvlan Netplan YAML below. |
| **NET-M04** | **Verify Macvlan VM Guest Networking & Host Isolation:** Inside the VM:<br>`ip -4 a`<br>`ip route`<br>`ping -c3 192.168.50.1`<br>`ping -c3 1.1.1.1`<br>`resolvectl status`<br>From the **Incus host**: `ping -c3 <VM_IP>`<br>From **another LAN host** (not the Incus host): `ping -c3 <VM_IP>` | `eth0` has `<VM_IP>/24`; default route `via 192.168.50.1`; guest pings gateway and `1.1.1.1`; DNS works; external LAN peer pings succeed. **Host-to-guest ping from the Incus host fails/times out** (expected Linux `macvlan` behavior). |
| **NET-M05** | **Provision & Verify Macvlan Container:** Deploy an LXC **Container** using `ubuntu/24.04`. Verify:<br>`incus config show <CT_NAME> --project <PROJECT> --expanded`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip -4 a`<br>`incus exec <CT_NAME> --project <PROJECT> -- ip route`<br>`incus exec <CT_NAME> --project <PROJECT> -- ping -c3 192.168.50.1`<br>`incus exec <CT_NAME> --project <PROJECT> -- ping -c3 1.1.1.1`<br>`incus exec <CT_NAME> --project <PROJECT> -- resolvectl status` | Container reaches `Running`. `devices.eth0` has `nictype: macvlan` and **NO `ipv4.address`**; `cloud-init.network-config` IS present with `<CT_IP>/24` and `via: 192.168.50.1`. Gateway, external ping, and DNS succeed. |

**Expected `incus config show <instance> --project <PROJECT> --expanded` excerpt for Macvlan (both VM and Container):**
```yaml
config:
  cloud-init.network-config: |
    network:
      version: 2
      ethernets:
        eth0:
          match:
            macaddress: "02:54:xx:xx:xx:xx"
          set-name: eth0
          addresses:
            - 192.168.50.3/24
          routes:
            - to: default
              via: 192.168.50.1
          nameservers:
            addresses:
              - 1.1.1.1
              - 8.8.8.8
devices:
  eth0:
    hwaddr: 02:54:xx:xx:xx:xx
    nictype: macvlan
    parent: <HOST_NIC>
    type: nic
```

*(Before proceeding to Mode 4, deactivate or delete the Macvlan test pool if staying on the same node.)*

---

## 8. Mode 4: Manual (`manual`) Test Cases

| Test ID | Steps in Ognivo UI & Commands | Expected Results |
| :--- | :--- | :--- |
| **NET-MAN01** | **Create Manual Pool:** In **Networking & IPAM -> Create Subnet Pool**, select the test node, `Network Mode: Manual (External)`, `CIDR: 192.168.50.0/24` *(example)*, `Gateway: 192.168.50.1` *(required by API for `/16`–`/30`)*, `Cooldown: 60`. Notice the **Host Parent Interface** field is hidden. Submit. | Pool is created (`201`) with `Manual` warning badge; `parent_interface` is `null` in API responses. |
| **NET-MAN02** | **Run Preflight Check:** Click **Preflight Check** on the manual pool. | Overall status is **Preflight checks passed**. `Parent interface exists` and `Parent interface type matches mode` pass with not-applicable hints. |
| **NET-MAN03** | **Provision Manual VM & Verify Operator-Managed Badge:** Deploy a KVM **Virtual Machine** using `ubuntu/24.04`. Wait for `Running`. Inspect:<br>`incus config show <VM_NAME> --project <PROJECT> --expanded`<br>Check the instance row in **Instances** (`VmTable`) and the **Overview** tab (`VmOverviewTab`). | `InstanceSpecBuilder` injects only the `root` disk device (no Ognivo `routed`/`bridged`/`macvlan` `eth0` device and no `cloud-init.network-config`; any `eth0` in `--expanded` comes solely from the Incus profile). VM transitions to `Running` with `network_status: unverified`, and the UI displays the amber **Operator-managed** badge with tooltip `"Manual network mode: guest networking is managed externally by the operator."` |

---

## 9. Negative & Validation Test Cases

| Test ID | Scenario & Steps | Expected Results |
| :--- | :--- | :--- |
| **NET-NEG01** | **Missing / Wrong `parent_interface` (Fail-Fast):** Create a `bridged` (or `routed`) pool and choose `Custom interface...` -> enter `br-nonexistent`. Run **Preflight Check**, then attempt to provision a VM on that pool. | **Preflight** fails on `'Parent interface exists'` (and `'Parent interface type matches mode'`). **Provisioning** fails permanently with NO fallback to another interface: VM transitions to `Failed` with a readable `failure_reason` naming `br-nonexistent`, the network mode, and the host's available interfaces. |
| **NET-NEG02** | **`macvlan` Over a Bridge (Blocking Preflight):** Create a `macvlan` pool whose `parent_interface` is a bridge (`incusbr0` or `br0`). Run **Preflight Check**. | **Preflight fails (blocking)** on `'Parent interface type matches mode'`, reporting that the bridge interface is incompatible with `macvlan` mode (`requires 'physical', 'bond', or 'vlan'`). |
| **NET-NEG03** | **`bridged` Over a Physical NIC (Blocking Preflight):** Create a `bridged` pool whose `parent_interface` is a physical NIC (`<HOST_NIC>`). Run **Preflight Check**. | **Preflight fails (blocking)** on `'Parent interface type matches mode'`, reporting that the physical interface is incompatible with `bridged` mode (`requires 'bridge'`). |
| **NET-NEG04** | **L2 Pool Without Gateway (`422`):** Attempt to create a `bridged` or `macvlan` pool with an empty `gateway` via UI and via `POST /api/v1/admin/ip-pools`. | UI displays validation error `"Gateway IP is required for bridged and macvlan pool modes."` Direct API call returns **HTTP `422`** with `errors.gateway`. |
| **NET-NEG05** | **L2 Pool With `/31` or `/32` CIDR (`422`):** Attempt to create a `bridged` or `macvlan` pool with `cidr: 192.168.50.10/32` or `192.168.50.10/31` via UI and API. | UI displays validation error for `/31` and `/32`. Direct API call returns **HTTP `422`** with `errors.cidr` (`"Bridged and macvlan modes only support /16 to /30 subnets."`). |
| **NET-NEG06** | **Edit `parent_interface` on Pool With Allocations:** On an active pool with at least one running VM/container (`VM_OLD`), open **Edit Pool**, verify `CIDR` and `Network Mode` are locked, change `Host Parent Interface` to another valid interface of the appropriate type, and save. Then provision a new instance (`VM_NEW`) or reinstall `VM_OLD`. Run `incus config show <VM_OLD> --project <PROJECT> --expanded` and `incus config show <VM_NEW> --project <PROJECT> --expanded`. | Save succeeds (`200`), showing the hint that changes apply only to newly created or rebuilt instances. `VM_OLD` keeps its original `devices.eth0.parent`, while `VM_NEW` (or a reinstalled instance) receives the updated `parent`. |

---

## 10. IPAM Cooldown, Ordering & Search Test Cases

| Test ID | Scenario & Steps | Expected Results |
| :--- | :--- | :--- |
| **NET-IPAM01** | **IP Release & Cooldown Quarantine:** On a pool with `cooldown_seconds: 60`, note the IP allocated to a test VM, delete the VM (or use admin **Force Release Allocation**), and watch **Networking & IPAM**. Before 60 seconds expire, provision another VM on the same pool; then wait > 60 seconds and up to 1 minute more for `ipam:prune-cooling`, and provision another VM. | Immediately after release, the IP appears in **Cooling** with a live countdown (`Cooling (59s)` -> `...`). A VM created before cooldown expires skips the cooling IP and receives the next free IP. `GET /api/v1/ips` does not delete the expired row on read; within ~1 minute after expiry, the scheduler (`ipam:prune-cooling`) prunes the row and the IP becomes allocatable again. |
| **NET-IPAM02** | **Numeric IPv4 Ordering:** Allocate several IPs in a `/24` pool (for example `.2`, `.9`, `.10`, `.100` — or seed/create enough instances to reach `.10`+) and inspect **Networking & IPAM** and `GET /api/v1/ips`. | Allocations list in numeric IPv4 order (`...2`, `...9`, `...10`, `...100`), not lexicographic string order (`...10`, `...100`, `...2`, `...9`). |
| **NET-IPAM03** | **Search & Status Filter Validation:** In **Networking & IPAM** (or via `GET /api/v1/ips`), search by IP prefix (e.g., `192.168.50.`), by MAC prefix (e.g., `02:54:`), and by instance/user name. Also test `GET /api/v1/ips?status=bogus` with `curl`. | IP prefix matches `ip_string` prefix; MAC prefix matches `mac_address` prefix case-insensitively; text matches VM, pool, or user name/email. `GET /api/v1/ips?status=bogus` returns **HTTP `422`**, while the UI networking hook ignores unsupported status filter values. |

---

## 11. Results Checklist

Copy this table into your QA report or ticket and fill in the observed results:

```markdown
**Test Date:** YYYY-MM-DD
**Incus Version (`incus version`):** <server/client version>
**Ognivo Branch / Commit:** <branch / git sha>

| Test ID | Mode / Area | Result (PASS/FAIL) | Notes / Observed Output |
| :--- | :--- | :--- | :--- |
| NET-R01 | Routed — Pool create | | |
| NET-R02 | Routed — Preflight | | |
| NET-R03 | Routed — VM spec & status | | |
| NET-R04 | Routed — VM connectivity | | |
| NET-R05 | Routed — Container spec & connectivity | | |
| NET-B01 | Bridged — Pool create | | |
| NET-B02 | Bridged — Preflight (6 checks) | | |
| NET-B03 | Bridged — VM spec & status | | |
| NET-B04 | Bridged — VM connectivity | | |
| NET-B05 | Bridged — Container spec & connectivity | | |
| NET-M01 | Macvlan — Pool create | | |
| NET-M02 | Macvlan — Preflight (6 checks) | | |
| NET-M03 | Macvlan — VM spec & status | | |
| NET-M04 | Macvlan — VM connectivity & host isolation | | |
| NET-M05 | Macvlan — Container spec & connectivity | | |
| NET-MAN01 | Manual — Pool create | | |
| NET-MAN02 | Manual — Preflight | | |
| NET-MAN03 | Manual — VM spec & Operator-managed badge | | |
| NET-NEG01 | Negative — Wrong parent_interface fail-fast | | |
| NET-NEG02 | Negative — Macvlan on bridge blocked | | |
| NET-NEG03 | Negative — Bridged on physical NIC blocked | | |
| NET-NEG04 | Negative — L2 pool without gateway (422) | | |
| NET-NEG05 | Negative — L2 pool with /31 or /32 (422) | | |
| NET-NEG06 | Edit — Change parent_interface with allocations | | |
| NET-IPAM01 | IPAM — Release, cooldown & prune | | |
| NET-IPAM02 | IPAM — Numeric IPv4 ordering | | |
| NET-IPAM03 | IPAM — Prefix/MAC search & GET /ips validation | | |
```

---

## 12. Cleanup

After completing the test run:
1. **Delete Test Instances in Ognivo:** Delete all test VMs and containers from the **Instances** view so Ognivo deletes them from Incus and releases their IP allocations.
2. **Prune Cooled IPs & Delete Test Pools:** Wait ~1 minute for `ipam:prune-cooling` (or click **Dismiss Cooled IP** in **Networking & IPAM**), then delete the temporary test IP pools.
3. **Remove Temporary Compute Node (if applicable):** If the Incus host was onboarded solely for testing, delete the node in **Compute Nodes** and revoke the panel's client certificate on the host (`incus config trust list` -> `incus config trust remove <fingerprint>`).
4. **Restore Incus Host Network Config:**
   - If you created `/etc/netplan/60-br0.yaml` solely for testing, restore your original Netplan config and run `sudo netplan apply`.
   - If you disabled DHCP on `incusbr0` for testing, re-enable it if needed (`incus network unset incusbr0 ipv4.dhcp`).
   - Revert temporary `sysctl` settings if they are not wanted permanently (reboot or set back to `0` if not running `routed` pools).
