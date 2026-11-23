# Network Modes & Host IPAM Architecture

Ognivo provides ranged **IPv4** subnet management (**IPAM**) with four operational network modes for pools assigned to hypervisor nodes: **Routed** (`routed`), **Bridged** (`bridged`), **Macvlan** (`macvlan`), and **Manual** (`manual`).

> [!IMPORTANT]
> **IPv4 only in 1.0.** IPv6 pools, allocation and dual-stack guests are not supported yet and are planned for a future release. See [IP Version Support](#9-ip-version-support-ipv4-only-in-10) for exactly what is enforced.

---

## 1. Routed Network Mode (`routed`)

In `routed` mode, instances communicate using point-to-point Layer 3 routing over a designated parent interface on the hypervisor host (for example `eno1`, `bond0`, or a bridge).

### Routed Instance Specification
- **Parent Interface (`parent_interface`):** Required, with no default value (`eth0` is never used as an automatic default).
- **NIC Device (`eth0`):** Incus `routed` NIC:
  - `type: nic`
  - `nictype: routed`
  - `parent: <parent_interface>`
  - `ipv4.address: <assigned_ip>`
  - `hwaddr: <mac_address>` (when the IP allocation has a generated MAC address `02:54:xx:xx:xx:xx`)
- **Cloud-Init Network Config (Virtual Machines & Containers):** For both `virtual-machine` and `container` instances in `routed` mode, `InstanceSpecBuilder` delegates to the Guest OS adapter layer (`GuestOsResolver`) to inject `cloud-init.network-config` configuring the `<assigned_ip>/32` address, point-to-point routes via the link-local next-hop `169.254.0.1`, and DNS `nameservers` (from `pool.dns_servers`, defaulting to `1.1.1.1` and `8.8.8.8`). Routed guests **always** use `169.254.0.1` as their default route next-hop—an optional Pool Gateway (`gateway`), if configured on the pool, is **never** injected into the guest OS configuration:
  - For **`virtual-machine`** instances, Netplan includes `match: { macaddress: ... }` and `set-name: eth0` to handle predictable interface naming (such as `enp5s0`).
  - For **`container`** instances, Netplan configures directly under `eth0:` (omitting `match` and `set-name`) to avoid `udev` unmanaged link conflicts in unprivileged LXC.
  - Modern Netplan deprecates `scope: link` for point-to-point `/32` routes; instead, routes use either `on-link: true` directly on the default route (as in Ubuntu) or an explicit host route to `169.254.0.1/32` alongside the default route (for `systemd-networkd`), depending on the guest OS strategy below. For example, the default Ubuntu Netplan v2 route block is:
  ```yaml
  routes:
    - to: 0.0.0.0/0
      via: 169.254.0.1
      on-link: true
  ```
- **Pool Rules:** CIDR prefix `/16` to `/32`. A Pool Gateway (`gateway`) is **not required** (optional for all supported CIDR prefixes `/16` to `/32`).
  - When omitted (`null`), no gateway address is reserved in the pool.
  - When configured, `gateway` must be a valid IPv4 address within the pool CIDR (and cannot be the network or broadcast address for `/16`–`/30`, nor fall within `169.254.0.0/16`).
  - In `routed` mode, an optional `gateway` serves **solely as an IPAM reservation**—it is dynamically excluded from IP allocation so it cannot be assigned to any instance, and is **never** used as the guest default route or injected into guest OS network configuration. It may be set, changed, or cleared at any time, provided the target address is not currently `allocated`.

### 1.1 Guest OS Routed Route Strategies
Because Linux and BSD guest distributions ship different network renderers behind `cloud-init`, Ognivo's Guest OS adapters generate four distinct routing configurations in `routed` mode:

1. **Ubuntu Style (`UbuntuOsAdapter`):**
   Uses a single Netplan v2 default route with `on-link: true` so Netplan directly attaches the off-subnet link-local gateway `169.254.0.1` to `eth0`:
   ```yaml
   routes:
     - to: 0.0.0.0/0
       via: 169.254.0.1
       on-link: true
   ```

2. **Debian / Arch / Gentoo / BSD Style (`DebianOsAdapter`, `ArchOsAdapter`, `GentooOsAdapter`, `BsdOsAdapter`):**
   Generates two Netplan v2 routes—an explicit host link route to `169.254.0.1/32` followed by the default route via `169.254.0.1`—which `systemd-networkd` requires to resolve the link-local gateway cleanly without `on-link`:
   ```yaml
   routes:
     - to: 169.254.0.1/32
     - to: 0.0.0.0/0
       via: 169.254.0.1
   ```
   *(Note: `BsdOsAdapter` targets `vtnet0` directly on KVM virtual machines without `match`/`set-name` interface renaming, and `eth0` on containers.)*

   > [!NOTE]
   > BSD routing and guest support (`BsdOsAdapter`) are experimental / roadmap features and are not available for provisioning in `1.0.0-alpha.1`. Only Linux guest workloads are supported in `1.0.0-alpha.1`.

3. **RHEL / SUSE Style (`RhelOsAdapter`, `SuseOsAdapter`):**
   Generates a single Netplan v2 default route without `on-link` for `sysconfig`/`NetworkManager` parser compatibility:
   ```yaml
   routes:
     - to: 0.0.0.0/0
       via: 169.254.0.1
   ```

4. **Alpine Linux Style (`AlpineOsAdapter`):**
   Uses Cloud-Init Network Config **version 1** (`version: 1`) YAML instead of Netplan v2, specifying a static `/32` subnet with `gateway: '169.254.0.1'` so Alpine's `ifupdown-ng` ENI renderer natively sets up the kernel on-link route:
   ```yaml
   version: 1
   config:
     - type: physical
       name: eth0
       mac_address: "02:54:xx:xx:xx:xx" # virtual-machine only
       subnets:
         - type: static
           address: "<assigned_ip>/32"
           gateway: "169.254.0.1"
           dns_nameservers:
             - 1.1.1.1
             - 8.8.8.8
   ```

### Required Hypervisor Host Configuration
Because the Incus REST API cannot modify host kernel `sysctl` parameters, operators must still configure host-side networking—IPv4 forwarding, Proxy ARP, and upstream routing or NAT—on each hypervisor host running `routed` pools, regardless of whether an optional IPAM Pool Gateway is configured. These host-side responsibilities are separate from both the optional IPAM Pool Gateway and the routed guest next-hop `169.254.0.1`:

1. **IPv4 Packet Forwarding:**
   ```bash
   sudo sysctl -w net.ipv4.ip_forward=1
   echo "net.ipv4.ip_forward = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   ```

2. **Proxy ARP / Neighbor Proxy:**
   When routing IPs from a subnet reachable over a host uplink interface (for example `eno1`), Proxy ARP allows the host kernel to answer ARP queries on behalf of the guest IP:
   ```bash
   sudo sysctl -w net.ipv4.conf.all.proxy_arp=1
   sudo sysctl -w net.ipv4.conf.eno1.proxy_arp=1
   echo "net.ipv4.conf.all.proxy_arp = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   echo "net.ipv4.conf.eno1.proxy_arp = 1" | sudo tee -a /etc/sysctl.d/99-ognivo.conf
   ```

For the complete firewall, NAT (`MASQUERADE`), and Docker `FORWARD` policy workaround with `iptables-persistent`, see [Compute Node Setup Guide — Host Network & Kernel Configuration for Routed Networking](../getting-started/compute-node-setup.md#5-host-network--kernel-configuration-for-routed-networking).

---

## 2. Bridged Network Mode (`bridged`)

In `bridged` mode, instances attach at Layer 2 directly to an existing Linux bridge (such as `br0`) or an Incus managed bridge (such as `incusbr0` or `lxdbr0`) on the hypervisor host. Both KVM virtual machines and LXC containers are supported.

### Bridged Instance Specification
- **NIC Device (`eth0`):** Incus `bridged` NIC:
  - `type: nic`
  - `nictype: bridged`
  - `parent: <parent_interface>`
  - `hwaddr: <mac_address>` (when present on the allocation)
  - **`ipv4.address` is NOT set** on the Incus NIC device.
- **Static IP via Cloud-Init (`cloud-init.network-config`):** For **both containers and virtual machines**, `InstanceSpecBuilder` generates a Netplan v2 configuration matching `hwaddr` (`set-name: eth0`) with the allocated IP and the pool's CIDR prefix, default route `via: <pool.gateway>`, and `nameservers.addresses` from `pool.dns_servers` (defaulting to `1.1.1.1` and `8.8.8.8`):
  ```yaml
  network:
    version: 2
    ethernets:
      eth0:
        match:
          macaddress: "02:54:xx:xx:xx:xx"
        set-name: eth0
        addresses:
          - 192.168.50.10/24
        routes:
          - to: default
            via: 192.168.50.1
        nameservers:
          addresses:
            - 1.1.1.1
            - 8.8.8.8
  ```

### Bridged Host Requirements
- **Bridge Parent Interface:** `parent_interface` must be a bridge on the host (Incus `GET /1.0/networks` must report `type: bridge`). Both unmanaged Linux bridges (for example `br0` enslaving a physical NIC) and Incus managed bridges (for example `incusbr0`) are accepted.
- **LAN Linux Bridge (`br0`):** Ensure the bridge enslaves the host uplink interface and has L2 reachability to the pool's `gateway`.
- **Incus Managed Bridge (`incusbr0`):** Ensure the pool's CIDR and `gateway` match the bridge's `ipv4.address` subnet and IP, and disable Incus DHCP (`ipv4.dhcp=false`) or keep the pool's usable range outside the DHCP range so addresses do not collide.

### Bridged Pool Rules & Limitations
- **`parent_interface`:** Required, with no default value.
- **`gateway`:** Required. Must be a valid IPv4 address inside the pool's CIDR and cannot be the network or broadcast address (or inside `169.254.0.0/16`). It is used as the guest default route, is dynamically excluded from IP allocation (not stored in `excluded_ips`), and becomes **immutable** once any allocations exist in the pool.
- **Prefix Length:** Must be between `/16` and `/30`. `/31` and `/32` subnets are rejected with HTTP `422`.
- **Cloud-Init Required:** Because the static IP, prefix, gateway, and DNS are applied through `cloud-init.network-config`, the guest image must support `cloud-init`. For Linux images from `images.linuxcontainers.org` (`simplestreams`), `InstanceSpecBuilder` automatically appends `/cloud` to the alias when `cloud-init` configuration is present.

---

## 3. Macvlan Network Mode (`macvlan`)

In `macvlan` mode, instances attach at Layer 2 directly to a physical NIC, bond, or VLAN interface on the hypervisor host using the Linux `macvlan` driver, without requiring a host bridge. Both KVM virtual machines and LXC containers are supported.

### Macvlan Instance Specification
- **NIC Device (`eth0`):** Incus `macvlan` NIC:
  - `type: nic`
  - `nictype: macvlan`
  - `parent: <parent_interface>`
  - `hwaddr: <mac_address>` (when present on the allocation)
  - **`ipv4.address` is NOT set** on the Incus NIC device.
- **Static IP via Cloud-Init (`cloud-init.network-config`):** Identical to `bridged` mode, `InstanceSpecBuilder` injects Netplan v2 YAML for **both containers and virtual machines** with `<ip>/<prefix>`, `via: <pool.gateway>`, and `nameservers.addresses`.

### Macvlan Host Requirements
- **Parent Interface Types:** `parent_interface` must be a `physical`, `bond`, or `vlan` interface on the host (for example `eno1`, `bond0`, or `bond0.100`). Bridge interfaces are rejected by preflight in `macvlan` mode.
- **Switch Port Security:** Upstream switch ports must allow multiple MAC addresses behind the host NIC.

### Macvlan Pool Rules & Limitations
- **Host Cannot Talk to Guests Directly:** By Linux `macvlan` kernel design, the hypervisor host cannot communicate directly with `macvlan` guests over the shared parent interface (and guests cannot reach the host IP on that interface). Guests can still reach the upstream gateway, other LAN hosts, and external networks.
- **Wireless NICs Generally Unsupported:** Wi-Fi interfaces in standard station mode reject arbitrary source MAC addresses and are generally unsuitable as `macvlan` parents; use a wired NIC, bond, or VLAN interface.
- **Cloud-Init Required:** Both containers and VMs require a `cloud-init`-capable image to receive their static IPv4 address, prefix, gateway, and DNS configuration.
- **Pool Rules:** Same Layer-2 validation as `bridged` mode: `parent_interface` is required (no default), `gateway` is required inside the CIDR and cannot be the network or broadcast address (or inside `169.254.0.0/16`), and the CIDR prefix must be `/16` to `/30` (`/31` and `/32` are rejected with HTTP `422`). As in `bridged` mode, `gateway` is dynamically excluded from IP allocation (not stored in `excluded_ips`) and becomes **immutable** once any allocations exist in the pool.

---

## 4. Manual Network Mode (`manual`)

In `manual` mode, guest networking is managed externally by the infrastructure operator (e.g., custom BGP daemon, Open vSwitch, OVN/SDN controller, or pre-configured host/project profiles).

### Manual Behavior & Instance Specification
- **No Parent Interface:** `parent_interface` is not used for `manual` pools; it is stored as an empty string and the API returns `null`.
- **No Injected NIC Device:** `InstanceSpecBuilder` does not inject an `eth0` device; interfaces are attached via profile or out-of-band tooling.
- **No Cloud-Init Netplan Override:** Static route/IP Netplan injection (`cloud-init.network-config`) is omitted.
- **Provisioning Status:** Instances transition to `Running` with `network_status: 'unverified'` unless an observed IP reported by the hypervisor agent matches the allocated IP.
- **UI Indicators:** Pools in `manual` mode display a `Manual` warning badge in **Networking & IPAM**, and VMs on `manual` pools show an **Operator-managed** network badge in the Instances table and overview tab when status is not `observed`.
- **Pool Rules:** CIDR `/16` to `/32`. `gateway` is required for `/16` to `/30` subnets and optional for `/31` and `/32`. When configured, `gateway` must be a valid IPv4 address inside the pool CIDR (not the network or broadcast address for `/16`–`/30`, nor inside `169.254.0.0/16`), is dynamically excluded from IP allocation (not stored in `excluded_ips`), and may be changed at any time provided the target address is not currently `allocated`.

---

## 5. Pool Validation & Fail-Fast Provisioning

### Mode Comparison
| Mode | `eth0` `nictype` | `ipv4.address` on NIC | `cloud-init.network-config` | Allowed CIDR | `gateway` | `parent_interface` |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `routed` | `routed` | Yes | Containers & VMs (`/32`, `via 169.254.0.1`) | `/16` – `/32` | Optional for `/16`–`/32` (IPAM reservation only; dynamically excluded if set) | Required (no default) |
| `bridged` | `bridged` | No | Containers & VMs (`/<prefix>`, `via <gateway>`) | `/16` – `/30` | Required, inside CIDR, not network/broadcast (immutable with allocations) | Required (`bridge`, no default) |
| `macvlan` | `macvlan` | No | Containers & VMs (`/<prefix>`, `via <gateway>`) | `/16` – `/30` | Required, inside CIDR, not network/broadcast (immutable with allocations) | Required (`physical`/`bond`/`vlan`, no default) |
| `manual` | *(omitted)* | No | No | `/16` – `/32` | Required for `/16`–`/30`; optional for `/31`–`/32` (dynamically excluded if set) | Unused (stored `""`, API `null`) |

### Fail-Fast Behavior (No Silent Interface Substitution)
- **Strict Validation at Pool Creation & Update:** `parent_interface` has no default value and is required for every non-manual pool (`routed`, `bridged`, `macvlan`).
- **Provisioning Verification:** During provisioning, `AbstractIncusLxdDriver::createInstance()` queries the host's networks (`GET /1.0/networks`) and passes them to `InstanceSpecBuilder::build()`.
  - If `parent_interface` is empty on a non-manual pool, `InstanceSpecBuilder` throws a `DomainException`.
  - If `parent_interface` is not present in the host's reported networks, `InstanceSpecBuilder` throws a `DomainException` (`Permanent network configuration error: Configured parent_interface "..." for network mode "..." was not found on the hypervisor host (available: ...).`). Ognivo **never** silently substitutes another interface.
  - `ProvisionVmJob` treats permanent configuration errors as non-retryable, rolls back reserved allocations via `ProvisioningCompensator`, and transitions the instance to `Failed` with the readable failure reason shown in the UI.
- **Updating Existing Pools:**
  - `parent_interface` can be updated even on pools that already have active or cooling allocations; the updated interface applies only to newly created or rebuilt/reinstalled instances, while running instances keep their existing device.
  - `cidr`, `host_id`, and `network_mode` remain immutable while any allocations exist.
  - For `bridged` and `macvlan` pools, `gateway` is **immutable** once any allocations exist in the pool, because existing instances already reference it in their Cloud-init network configuration.
  - For `routed` and `manual` pools, `gateway` may be set, changed, or cleared at any time, except that changing `gateway` (or `excluded_ips`) to an address currently in status `allocated` is rejected with HTTP `422` (naming the allocation ID and address).
- **Dynamic IPAM Exclusions:**
  - Across **all** network modes, any configured Pool Gateway (`gateway`) is **dynamically excluded** from IP allocation at runtime and is **never** persisted in `excluded_ips`.
  - `excluded_ips` stores only administrator-defined exclusions.
  - When a `gateway` is set, changed, or cleared (on pools where `gateway` is mutable), effective exclusions update immediately: the new gateway becomes unavailable for allocation, and the previous gateway address returns to circulation unless explicitly listed in `excluded_ips`.
  - Link-local addresses in `169.254.0.0/16` are rejected for pool CIDRs, `gateway`, and `excluded_ips` (`169.254.0.1` is reserved exclusively as the routed guest link-local next-hop).
- **Host Interface Recommendations:** When discovering host networks (`GET /api/admin/hosts/{host}/resources`), Ognivo recommends `bridged` and `routed` for `bridge` interfaces, and `routed` and `macvlan` for `physical`, `bond`, and `vlan` interfaces.

---

## 6. Allocation Cooldown Period

- **IP Cooling Period:** Released IP allocations enter a `cooling` state (`released_at`) for a pool-configured duration (`cooldown_seconds`) before returning to general circulation. During this quarantine window, the address cannot be immediately reassigned, preventing network disruption and traffic leakage from previous instances.
- **Expiration & Cleanup:** `GET /api/ips` does not delete rows on read and validates query parameters (`user_id`, `host_id`, `pool_id`, `status` in `allocated`/`cooling`, `search`, `per_page`, `page`). Expired `cooling` rows are removed only by the scheduled `ipam:prune-cooling` command (every minute); until pruned, an expired row is shown with status `'released'` and `cooldown_remaining_seconds` `0`, and `allocateIp` already treats it as reusable.
- **Allocation Ordering:** The allocations list is ordered by pool, then numerically by IPv4 address.
- **Allocation Search:** The allocations `search` filter trims whitespace and classifies the query: IPv4 terms (e.g. `10.0.0.1`, `10.0`) match `ip_string` by prefix (`term%`), MAC prefixes containing `:` (e.g. `02:54:ab`) match `mac_address` by case-insensitive prefix (`term%`), and other text matches user `name`/`email` (as well as VM and pool names) by substring (`%term%`) with SQL `LIKE` wildcards (`%`, `_`, `\`) escaped.

---

## 7. Preflight Pool Verification

Before putting an IP pool into production, administrators can trigger a preflight check (`POST /api/admin/ip-pools/{ipPool}/preflight`), which evaluates:
1. **`Host reachable`:** Authenticated mTLS reachability to the assigned host node (`GET /1.0/networks?recursion=1`).
2. **`Parent interface exists`:** Confirms the pool's `parent_interface` exists on the remote host (passes as not used in `manual` mode; fails if empty or absent on the host).
3. **`Parent interface type matches mode`:**
   - For `bridged`, **fails (blocking)** unless Incus reports type `bridge` (Linux bridge or Incus managed bridge).
   - For `macvlan`, **fails (blocking)** unless Incus reports type `physical`, `bond`, or `vlan`.
   - For `routed`, accepts `physical`, `bond`, `vlan`, or `bridge` (and passes with an advisory hint for other reported types).
   - For `manual`, passes as not applicable.
   - If Incus returns no `type` (or `unknown`), the check passes as unverifiable with a hint instructing the operator to verify the interface matches the chosen mode.
4. **`Pool range consistent`:** Validates that `first_usable` and `last_usable` (and `gateway`, when configured) fall within the CIDR subnet boundaries and `first_usable <= last_usable`.
5. **`Gateway configured`:** Enforces the mode-specific gateway policy: required and valid within the CIDR (not network/broadcast) for `bridged` and `macvlan`; required for `/16`–`/30` and optional for `/31`–`/32` in `manual` mode; optional for `routed` pools (preflight succeeds without a gateway in `routed` mode).
6. **`Host sysctl & proxy ARP`:** Advisory check reminding the operator that host kernel parameters (`net.ipv4.ip_forward=1`, `proxy_arp`) cannot be verified remotely via the Incus API.

> [!NOTE]
> Preflight checks do not inspect host kernel `sysctl` settings (`net.ipv4.ip_forward` or `proxy_arp`) as those are not exposed by the Incus REST API. For `routed` pools, verify host kernel parameters directly using the commands in [Section 1](#1-routed-network-mode-routed).

---

## 8. Pool Address & Free-IP Inspector

Administrators can inspect every IPv4 address across a pool's usable range (`first_usable` through `last_usable`) via `GET /api/admin/ip-pools/{ipPool}/addresses` and the **PoolDetailModal** in the Admin UI (**Networking & IPAM** — accessible via the Eye button, clickable pool name, or `Free: N` badge on any pool card).

### Query Parameters & Filtering
- **`state`:** Filters addresses by effective state:
  - `all` *(default)* — entire usable range in numeric IPv4 order
  - `free` — available addresses ready for assignment
  - `allocated` — currently assigned to an instance
  - `cooling` — quarantined in cooldown after release
  - `excluded` — excluded from allocation (including any dynamically excluded pool gateway)
- **`search`:** Optional IPv4 prefix filter (max 32 characters, e.g. `10.0.1.`).
- **`page` & `per_page`:** 1-based page number and page size (`1`–`100`, default `50`), returning canonical `meta` pagination alongside `pool` and `data`.

### State & Performance Semantics
- **Gateway Tagging (`is_gateway`):** Addresses matching `pool.gateway` include `is_gateway: true` so operators can distinguish the dynamically excluded gateway from administrator-defined `excluded_ips` entries (the gateway itself is not stored in `excluded_ips`).
- **Expired Cooling Rows Match `utilization.free`:** Cooling allocations whose cooldown has elapsed (`cooldown_remaining_seconds === 0`, awaiting scheduled `ipam:prune-cooling` cleanup) are treated and counted as `free`, keeping the inspector aligned with `utilization.free`.
- **`O(page)` Gap-Jumping for `/16` Pools:** When paginating unsearched `all` or `free` views, the service skips over gaps between occupied/excluded addresses in `O(page)` rather than enumerating all host addresses in memory, keeping large `/16` subnets (65,534 usable hosts) responsive.

---

## 9. IP Version Support (IPv4 only in 1.0)

Ognivo 1.0 allocates and tracks **IPv4 addresses only**.

| Aspect | Behavior in 1.0 |
| :--- | :--- |
| Pool creation | `POST /api/admin/ip-pools` rejects an `ip_version` other than `4` with a validation error on `ip_version` (`messages.ip.ipv6_not_supported_v1`: "IPv6 is not supported in v1.0."). Omitting `ip_version` creates an IPv4 pool |
| Pool CIDR | Must be a valid **IPv4** CIDR with a prefix length between `/16` and `/32` (`/16` to `/30` for `bridged` and `macvlan`). An IPv6 CIDR fails validation |
| Instance NIC | Only IPv4 addresses are configured (`ipv4.address` on `routed` NICs, Netplan IPv4 `addresses` for `bridged`/`macvlan` and `routed` instances). No IPv6 address is assigned or tracked by Ognivo |
| Host configuration | The required `sysctl` settings in [Section 1](#1-routed-network-mode-routed) are IPv4 settings (`ip_forward`, `proxy_arp`). No IPv6 forwarding or NDP proxy setup is needed or managed |
| Quotas | The per-user IP quota (`max_ips`) counts IPv4 allocations |

> [!NOTE]
> Ognivo does not manage IPv6 for guests. Whatever a guest image or the underlying Incus network does on its own (for example, link-local IPv6 addresses) is outside Ognivo's control and is not shown in IPAM.

### Planned for a future release

IPv6 pool support is on the roadmap (see the README and `CHANGELOG.md`) but has no committed version. Until it ships, do not rely on the schema columns that hint at IPv6 support (for example `virtual_machines.ipv6_address`); they are unused groundwork and may change.
