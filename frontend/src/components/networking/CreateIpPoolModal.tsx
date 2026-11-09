"use client";

import React, { useState, useEffect } from "react";
import { X, Network, Server, Loader2, CheckCircle2 } from "lucide-react";
import { api, IpPool, HostNode } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface CreateIpPoolModalProps {
  onClose: () => void;
  onSuccess: (newPool: IpPool) => void;
}

function calculateUsableRange(cidr: string): { first: string; last: string; gateway: string } | null {
  const parts = cidr.trim().split("/");
  if (parts.length !== 2) return null;
  const ipStr = parts[0];
  const prefix = parseInt(parts[1], 10);
  if (isNaN(prefix) || prefix < 16 || prefix > 32) return null;

  const octets = ipStr.split(".").map(Number);
  if (octets.length !== 4 || octets.some((o) => isNaN(o) || o < 0 || o > 255)) return null;

  const ipNum = ((octets[0] << 24) | (octets[1] << 16) | (octets[2] << 8) | octets[3]) >>> 0;
  const mask = prefix === 32 ? 0xffffffff : prefix === 0 ? 0 : (0xffffffff << (32 - prefix)) >>> 0;
  const network = (ipNum & mask) >>> 0;
  const broadcast = (network | (~mask >>> 0)) >>> 0;

  const numToIp = (n: number) =>
    [(n >>> 24) & 255, (n >>> 16) & 255, (n >>> 8) & 255, n & 255].join(".");

  if (prefix === 32) {
    return {
      gateway: "",
      first: ipStr,
      last: ipStr,
    };
  }

  if (prefix === 31) {
    return {
      gateway: "",
      first: numToIp(network),
      last: numToIp(broadcast),
    };
  }

  return {
    gateway: numToIp(network + 1),
    first: numToIp(network + 2),
    last: numToIp(broadcast - 1),
  };
}

export function CreateIpPoolModal({ onClose, onSuccess }: CreateIpPoolModalProps) {
  const { t } = useI18n();

  const [name, setName] = useState("");
  const [cidr, setCidr] = useState("");
  const [firstUsable, setFirstUsable] = useState("");
  const [lastUsable, setLastUsable] = useState("");
  const [parentInterface, setParentInterface] = useState("eth0");
  const [networkMode, setNetworkMode] = useState<"routed" | "manual">("routed");
  const [gateway, setGateway] = useState("");
  const [dnsServers, setDnsServers] = useState("1.1.1.1, 8.8.8.8");
  const [excludedIps, setExcludedIps] = useState("");
  const [cooldownSeconds, setCooldownSeconds] = useState(300);
  const [description, setDescription] = useState("");
  const [isPublic, setIsPublic] = useState(true);
  const [hosts, setHosts] = useState<HostNode[]>([]);
  const [selectedHostId, setSelectedHostId] = useState<number | null>(null);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api.getHosts().then((res) => {
      setHosts(res.data);
      if (res.data.length > 0 && selectedHostId === null) {
        setSelectedHostId(res.data[0].id);
      }
    }).catch(console.error);
  }, [selectedHostId]);

  const handleCidrChange = (val: string) => {
    setCidr(val);
    const calculated = calculateUsableRange(val);
    if (calculated) {
      if (!firstUsable || firstUsable === calculated.first || firstUsable.startsWith(val.split(".")[0])) {
        setFirstUsable(calculated.first);
      }
      if (!lastUsable || lastUsable === calculated.last || lastUsable.startsWith(val.split(".")[0])) {
        setLastUsable(calculated.last);
      }
      if (!gateway) {
        setGateway(calculated.gateway);
      }
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!selectedHostId) {
      setError("Please select a target host node for this routed subnet pool.");
      return;
    }

    try {
      setLoading(true);

      const parsedDns = dnsServers
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s.length > 0);

      const parsedExcluded = excludedIps
        .split(",")
        .map((s) => s.trim())
        .filter((s) => s.length > 0);

      const res = await api.adminCreateIpPool({
        name,
        host_id: selectedHostId,
        cidr: cidr.trim(),
        first_usable: firstUsable.trim() || undefined,
        last_usable: lastUsable.trim() || undefined,
        parent_interface: parentInterface.trim() || "eth0",
        network_mode: networkMode,
        gateway: gateway.trim() || undefined,
        dns_servers: parsedDns.length > 0 ? parsedDns : undefined,
        excluded_ips: parsedExcluded.length > 0 ? parsedExcluded : undefined,
        cooldown_seconds: Number(cooldownSeconds) || 300,
        is_public: isPublic,
        description: description.trim() || undefined,
      });

      onSuccess(res.pool);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError("Failed to create IP pool.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fadeIn">
      <div className="relative w-full max-w-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-3xl p-6 sm:p-8 shadow-2xl overflow-y-auto max-h-[90vh] transition-colors">
        <div className="flex items-center justify-between pb-4 mb-6 border-b border-gray-100 dark:border-gray-800">
          <div className="flex items-center space-x-3">
            <div className="p-2.5 rounded-2xl bg-teal-500/10 text-teal-600 dark:text-teal-400">
              <Network className="w-6 h-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white tracking-tight">{t("addPool")}</h2>
              <p className="text-xs text-gray-500 dark:text-gray-400">Routed L3 IP Subnet Pool</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {error && (
          <div className="mb-5 p-3.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400">
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("poolName")}
              </label>
              <input
                type="text"
                required
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Node 1 Routed Public Subnet"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center space-x-1.5">
                <Server className="w-3.5 h-3.5 text-teal-500" />
                <span>Target Node (Required)</span>
              </label>
              <select
                required
                value={selectedHostId ?? ""}
                onChange={(e) => setSelectedHostId(Number(e.target.value))}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              >
                <option value="" disabled>Select Compute Node...</option>
                {hosts.map((h) => (
                  <option key={h.id} value={h.id}>
                    {h.name} {h.region ? `(${h.region})` : ""}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                CIDR Subnet Block
              </label>
              <input
                type="text"
                required
                value={cidr}
                onChange={(e) => handleCidrChange(e.target.value)}
                placeholder="203.0.113.0/24"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
              <span className="text-[11px] text-gray-400 mt-1 block">Valid IPv4 prefix (/16 to /32)</span>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                {t("networkMode")}
              </label>
              <select
                value={networkMode}
                onChange={(e) => setNetworkMode(e.target.value as "routed" | "manual")}
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              >
                <option value="routed">{t("modeRouted")}</option>
                <option value="manual">{t("modeManual")}</option>
              </select>
              <span className="text-[11px] text-gray-400 mt-1 block">
                {networkMode === "manual" ? t("manualWarning") : "Routed L3 via host NIC (Netplan v2 link-local)"}
              </span>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Host Parent Interface
              </label>
              <input
                type="text"
                required
                value={parentInterface}
                onChange={(e) => setParentInterface(e.target.value)}
                placeholder="eth0"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
              <span className="text-[11px] text-gray-400 mt-1 block">Host NIC for routed L3 traffic (default: eth0)</span>
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Gateway IP (Optional for /31, /32)
              </label>
              <input
                type="text"
                value={gateway}
                onChange={(e) => setGateway(e.target.value)}
                placeholder="203.0.113.1"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
              <span className="text-[11px] text-gray-400 mt-1 block">Automatically added to excluded IP set</span>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                First Usable IP
              </label>
              <input
                type="text"
                value={firstUsable}
                onChange={(e) => setFirstUsable(e.target.value)}
                placeholder="203.0.113.2"
                className="w-full px-3 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Last Usable IP
              </label>
              <input
                type="text"
                value={lastUsable}
                onChange={(e) => setLastUsable(e.target.value)}
                placeholder="203.0.113.254"
                className="w-full px-3 py-2 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-xs text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50 font-mono"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                DNS Servers
              </label>
              <input
                type="text"
                value={dnsServers}
                onChange={(e) => setDnsServers(e.target.value)}
                placeholder="1.1.1.1, 8.8.8.8"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                Cooldown Quarantine (Seconds)
              </label>
              <input
                type="number"
                min={0}
                max={86400}
                value={cooldownSeconds}
                onChange={(e) => setCooldownSeconds(Number(e.target.value))}
                placeholder="300"
                className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
              />
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              Excluded Reserved IPs
            </label>
            <input
              type="text"
              value={excludedIps}
              onChange={(e) => setExcludedIps(e.target.value)}
              placeholder="e.g. 203.0.113.1, 203.0.113.250"
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
            />
            <span className="text-[11px] text-gray-400 mt-1 block">Comma-separated IPs excluded from tenant allocation</span>
          </div>

          <div>
            <label className="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1.5">
              {t("description")}
            </label>
            <input
              type="text"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="e.g. Primary DMZ public IP subnet on host-1"
              className="w-full px-3.5 py-2.5 rounded-xl bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-teal-500/50"
            />
          </div>

          <div className="p-3.5 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60">
            <label className="flex items-center space-x-2.5 cursor-pointer">
              <input
                type="checkbox"
                checked={isPublic}
                onChange={(e) => setIsPublic(e.target.checked)}
                className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
              />
              <span className="text-xs font-medium text-gray-800 dark:text-gray-200">{t("isPublic")}</span>
            </label>
          </div>

          <div className="flex items-center justify-end space-x-3 pt-4 border-t border-gray-100 dark:border-gray-800">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2.5 rounded-xl text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              {t("cancel")}
            </button>
            <button
              type="submit"
              disabled={loading}
              className="flex items-center space-x-2 px-5 py-2.5 rounded-xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-semibold shadow-md shadow-teal-500/20 transition disabled:opacity-50"
            >
              {loading ? <Loader2 className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
              <span>{t("createPoolAction")}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
