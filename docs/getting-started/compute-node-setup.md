# Compute Node Setup Guide

This guide explains how to prepare a physical or virtual Linux server from scratch as an **Incus compute node** for **Ognivo**.

> [!NOTE]
> **Supported operating systems:** Debian 12 (Bookworm) and Ubuntu 22.04 LTS / 24.04 LTS.
> **Supported hypervisor:** Incus 6.0 LTS or newer (LXD is experimental).

---

## 1. Overview

In the Ognivo architecture, the **control plane** (the Ognivo web UI, API, workers, and database) is separated from the **compute nodes** that run workloads:

- **Control plane (Ognivo Panel):** Manages users, projects, IP pools, billing/quotas, and schedules operations. It runs in Docker Compose and does not need to host guest workloads itself.
- **Compute node (Incus Host):** A Linux server running the Incus daemon. It hosts system containers and KVM virtual machines, manages local storage pools and host networking, and exposes the Incus HTTPS API over mutual TLS (mTLS) on TCP port `8443` for the Ognivo panel to manage.

You can run Ognivo with one or many compute nodes. Even in single-server deployments where the Ognivo panel and Incus share the same host, the compute node configuration steps below still apply—especially the Docker `FORWARD` chain rules in [Section 5](#5-host-network--kernel-configuration-for-routed-networking).

---

## 2. Hardware & Virtualization Check

System containers work on almost any modern Linux kernel, while **KVM virtual machines** require hardware virtualization extensions (`VT-x` on Intel or `AMD-V` on AMD) and the `/dev/kvm` device.

Check CPU virtualization support:

```bash
egrep -c '(vmx|svm)' /proc/cpuinfo
```

A result greater than `0` means hardware virtualization is enabled. If it returns `0`, enable **Intel VT-x** or **AMD-V** in the server BIOS/UEFI (or enable **nested virtualization** on the outer hypervisor if this compute node is itself a virtual machine).

Verify that the KVM kernel device is available and accessible:

```bash
ls -l /dev/kvm
```

Expected output looks similar to:

```text
crw-rw----+ 1 root kvm 10, 232 Nov 20 12:00 /dev/kvm
```

If `/dev/kvm` is missing, load the KVM module for your CPU vendor (`kvm_intel` or `kvm_amd`) or check your virtualization BIOS settings:

```bash
sudo modprobe kvm
sudo modprobe kvm_intel   # or: sudo modprobe kvm_amd
```

---

## 3. Incus Installation

Distribution repositories often ship outdated Incus packages or omit them entirely. Install **Incus 6.0 LTS** from the official Zabbly package repository (`pkgs.zabbly.com`).

### Add the Zabbly APT repository

1. Install prerequisites and import the Zabbly GPG signing key:

   ```bash
   sudo apt update
   sudo apt install -y curl gnupg ca-certificates
   sudo mkdir -p /etc/apt/keyrings
   sudo curl -fsSL https://pkgs.zabbly.com/key.asc -o /etc/apt/keyrings/zabbly.asc
   ```

2. Add the `zabbly-incus-lts-6.0` repository using your OS codename from `/etc/os-release`:

   ```bash
   . /etc/os-release
   sudo tee /etc/apt/sources.list.d/zabbly-incus-lts-6.0.sources <<EOF
   Enabled: yes
   Types: deb
   URIs: https://pkgs.zabbly.com/incus/lts-6.0
   Suites: ${VERSION_CODENAME}
   Components: main
   Architectures: $(dpkg --print-architecture)
   Signed-By: /etc/apt/keyrings/zabbly.asc
   EOF
   ```

3. Update package lists and install Incus:

   ```bash
   sudo apt update
   sudo apt install -y incus
   ```

   If you plan to run KVM virtual machines (recommended for full VPS support), also verify that `qemu-system` was pulled in by the `incus` package. On minimal installations you can inspect or install `incus-ui-canonical` optionally, though Ognivo serves as the management UI.

4. Verify the installed version:

   ```bash
   incus --version
   ```

### Initialize Incus

Run the interactive initialization wizard:

```bash
sudo incus admin init
```

Follow these recommendations when answering the prompts:

| Prompt | Recommendation | Notes |
| :--- | :--- | :--- |
| Clustering | `no` (for standalone nodes) | Add each standalone node individually in Ognivo |
| Storage pool | **ZFS** or **Btrfs** (or `dir` for testing) | **ZFS** is recommended for production (fast copy-on-write clones, snapshots, and per-instance disk quotas). **Btrfs** is a good alternative on single-disk setups. Avoid `dir` in production because it duplicates disk usage on snapshots/clones and lacks native block-level thin provisioning |
| Storage backend source | Dedicated partition/disk or loop file | Prefer a dedicated block device or partition over a loop-backed file in production |
| Network bridge (`incusbr0`) | `yes` (if using NAT/private bridge) or `no` (for pure routed/macvlan) | Keep a default `incusbr0` bridge if you plan to use bridged networking alongside routed networking |
| Remote API (`core.https_address`) | Set to `<NODE_IP>:8443` | Can also be configured afterwards (see [Section 6](#6-remote-api--trust-setup)) |

> [!TIP]
> If you choose ZFS as your storage backend and do not already have `zfsutils-linux` installed, install it before running `incus admin init`:
> ```bash
> sudo apt install -y zfsutils-linux
> ```

---

## 4. Mandatory Host Packages & Utilities

Install the required host utilities that Incus and Ognivo's networking model depend on:

```bash
sudo apt update
sudo apt install -y genisoimage iptables-persistent
```

### Why each package is required

1. **`genisoimage` (or `xorriso` / `mkisofs`):**
   Incus requires `genisoimage` or `mkisofs` on the host to generate the `agent:config` ISO device attached to KVM virtual machines. This ISO delivers cloud-init / `incus-agent` bootstrap configuration into guest VMs across diverse Linux guest distributions and Windows—**specifically including RHEL, AlmaLinux, Rocky Linux, Alpine, and Windows VM bootstrapping**.

   > [!IMPORTANT]
   > Without `genisoimage` (or `mkisofs`) installed on the compute node, starting affected KVM virtual machines fails with:
   > ```text
   > Neither mkisofs nor genisoimage could be found in $PATH
   > ```

2. **`iptables-persistent` (and `netfilter-persistent`):**
   Saves your IPv4 NAT and `FORWARD` firewall rules to `/etc/iptables/rules.v4` and automatically restores them when the compute node reboots. Without it, custom `iptables` rules are lost on reboot and guests lose external connectivity.

---

## 5. Host Network & Kernel Configuration for Routed Networking

When Ognivo provisions instances in **routed** networking mode, traffic passes through the host's routing table rather than a standard NATted bridge, and the host answers ARP requests on behalf of guest IPs (Proxy ARP).

### Kernel Sysctl Configuration

Create `/etc/sysctl.d/99-ognivo.conf` with IPv4 forwarding and Proxy ARP enabled. Replace `<INTERFACE>` with your host's primary uplink network interface name (for example `eth0`, `ens3`, or `enp1s0` — check with `ip -br link`).

```bash
sudo tee /etc/sysctl.d/99-ognivo.conf <<'EOF'
# Enable IPv4 packet forwarding for routed instances
net.ipv4.ip_forward = 1

# Enable Proxy ARP globally and on the primary uplink interface
net.ipv4.conf.all.proxy_arp = 1
net.ipv4.conf.<INTERFACE>.proxy_arp = 1
EOF
```

Apply the kernel parameters immediately:

```bash
sudo sysctl --system
```

Verify the active values:

```bash
sysctl net.ipv4.ip_forward net.ipv4.conf.all.proxy_arp net.ipv4.conf.<INTERFACE>.proxy_arp
```

### NAT & Firewall Rules (`iptables`)

#### Why NAT is required for private IPv4 pools

If your routed IPv4 pool uses private **RFC 1918** addresses (such as `10.0.0.0/8`, `172.16.0.0/12`, or `192.168.0.0/16`), upstream routers will not route those source addresses on the public internet. The compute node must perform **Source NAT (`MASQUERADE`)** on outbound traffic leaving the subnet while leaving instance-to-instance traffic inside `<SUBNET_CIDR>` un-NATted.

#### Resolving Docker's `FORWARD` chain `DROP` policy

If **Docker** is installed on the same host (for example on single-host deployments running both Ognivo and Incus, or alongside other containerized services), Docker changes the default policy of the `iptables` `FORWARD` chain from `ACCEPT` to **`DROP`**. When `FORWARD` defaults to `DROP`, forwarded packets between Incus instances and the host uplink are silently dropped—cutting off guest internet access even when `net.ipv4.ip_forward = 1` is set.

Insert explicit `ACCEPT` rules at the top of the `FORWARD` chain and add the `MASQUERADE` rule for your routed subnet (replace `<SUBNET_CIDR>`, for example `10.10.0.0/24`):

```bash
sudo iptables -t nat -A POSTROUTING -s <SUBNET_CIDR> ! -d <SUBNET_CIDR> -j MASQUERADE
sudo iptables -I FORWARD -s <SUBNET_CIDR> -j ACCEPT
sudo iptables -I FORWARD -d <SUBNET_CIDR> -m state --state RELATED,ESTABLISHED -j ACCEPT
sudo netfilter-persistent save
```

> [!NOTE]
> - `-I FORWARD` inserts the rules at the top of the `FORWARD` chain so they are evaluated before Docker's isolation/drop rules.
> - If you manage multiple routed subnets on the same compute node, repeat the three `iptables` rules for each `<SUBNET_CIDR>` and run `sudo netfilter-persistent save` again.
> - If your routed pool uses **publicly routable IPv4 addresses** assigned directly to the host, you do **not** need the `MASQUERADE` rule, though you still need the `FORWARD` `ACCEPT` rules if Docker is present or the `FORWARD` policy is `DROP`.

### Automated Guest OS Compatibility

> [!NOTE]
> Compute node operators do **not** need to maintain custom Incus profiles or run manual CLI tweaks for non-SecureBoot guest operating systems (such as Alpine, Arch, Gentoo, openEuler, or BSD). Ognivo's Guest OS adapter layer automatically injects `security.secureboot: "false"` into the instance specification when provisioning those virtual machines.

---

## 6. Remote API & Trust Setup

By default, Incus only listens on a local Unix socket. To allow the Ognivo panel to manage this compute node remotely over mTLS:

1. **Bind Incus to the node's network HTTPS address (port `8443`):**

   ```bash
   incus config set core.https_address <NODE_IP>:8443
   ```

   Replace `<NODE_IP>` with the IP address reachable by the Ognivo panel (or `0.0.0.0:8443` if you control access via firewall rules).

2. **Generate a one-time trust token for the Ognivo panel:**

   ```bash
   incus config trust add ognivo-panel
   ```

   Copy the printed token and paste it into the Ognivo admin UI when onboarding the node. For details on registering the node in Ognivo, see [Connect Your First Incus Node](installation.md#6-connect-your-first-incus-node) and [Host Onboarding & mTLS](../features/host-onboarding.md).

> [!WARNING]
> **Restrict port `8443/tcp` at the network firewall.** Only the Ognivo panel's IP address should be permitted to reach `<NODE_IP>:8443`. Never leave the Incus API port open to the public internet without source-IP filtering.

---

## 7. Verification Checklist

Run the following checks on the compute node before onboarding it in Ognivo:

| Check | Command | Expected Result |
| :--- | :--- | :--- |
| KVM hardware support | `ls -l /dev/kvm` | Character device `/dev/kvm` exists |
| Incus version | `incus --version` | `6.0` or newer |
| `genisoimage` installed | `command -v genisoimage` | Prints `/usr/bin/genisoimage` |
| `netfilter-persistent` installed | `command -v netfilter-persistent` | Prints `/usr/sbin/netfilter-persistent` |
| IPv4 forwarding enabled | `sysctl -n net.ipv4.ip_forward` | `1` |
| Proxy ARP enabled | `sysctl -n net.ipv4.conf.all.proxy_arp` | `1` |
| NAT `MASQUERADE` rule active | `sudo iptables -t nat -L POSTROUTING -n -v` | Shows `MASQUERADE` rule for `<SUBNET_CIDR>` |
| `FORWARD` `ACCEPT` rules active | `sudo iptables -L FORWARD -n -v` | Shows `ACCEPT` rules for `<SUBNET_CIDR>` above any `DROP` policy |
| Incus listening on port `8443` | `sudo ss -tlnp \| grep 8443` | Shows `incusd` listening on `<NODE_IP>:8443` |

Quick one-line check script:

```bash
echo "genisoimage: $(command -v genisoimage)" && \
echo "ip_forward: $(sysctl -n net.ipv4.ip_forward)" && \
sudo iptables -t nat -S POSTROUTING | grep MASQUERADE && \
sudo ss -tlnp | grep ':8443'
```
