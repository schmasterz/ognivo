"use client";

import React from "react";
import {
  Cpu,
  Layers,
  HardDrive,
  Globe,
  Server,
  Box,
  Clock,
  Shield,
  User as UserIcon,
} from "lucide-react";
import { VirtualMachine, User } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

interface VmOverviewTabProps {
  vm: VirtualMachine;
  user: User | null;
  isAdmin: boolean;
  onVmUpdated?: (vm: VirtualMachine) => void;
}

export function VmOverviewTab({ vm, isAdmin }: VmOverviewTabProps) {
  const { t } = useI18n();

  const primaryIp = vm.network?.primary?.address ?? vm.ipv4_address ?? "—";
  const macAddress = vm.network?.primary?.mac_address ?? vm.mac_address ?? "—";
  const netStatus = vm.network?.primary?.status ?? vm.network_status ?? "unverified";
  const gateway = vm.network?.settings?.gateway ?? "—";
  const dnsServers = vm.network?.settings?.dns?.length
    ? vm.network.settings.dns.join(", ")
    : "—";
  const netMode = vm.network?.settings?.mode ?? "—";

  const getNetworkStatusBadge = (status: string) => {
    switch (status.toLowerCase()) {
      case "assigned":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5" />
            Assigned
          </span>
        );
      case "observed":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-blue-500 mr-1.5" />
            Observed
          </span>
        );
      case "mismatch":
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20">
            <span className="w-1.5 h-1.5 rounded-full bg-red-500 mr-1.5" />
            Mismatch
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-zinc-200 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
            <span className="w-1.5 h-1.5 rounded-full bg-zinc-400 mr-1.5" />
            Unverified
          </span>
        );
    }
  };

  return (
    <div className="space-y-6">
      {/* Specifications Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        {/* CPU */}
        <div className="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 flex items-center space-x-3.5">
          <div className="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
            <Cpu className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
              {t("cpu")}
            </div>
            <div className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
              {vm.cpu_cores} Cores
            </div>
          </div>
        </div>

        {/* RAM */}
        <div className="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 flex items-center space-x-3.5">
          <div className="p-2.5 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
            <Layers className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
              {t("ram")}
            </div>
            <div className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
              {Math.round((vm.ram_mb / 1024) * 10) / 10} GB
            </div>
          </div>
        </div>

        {/* Storage */}
        <div className="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 flex items-center space-x-3.5">
          <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <HardDrive className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
              {t("disk")}
            </div>
            <div className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
              {vm.disk_gb} GB
            </div>
          </div>
        </div>

        {/* Instance Type */}
        <div className="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 flex items-center space-x-3.5">
          <div className="p-2.5 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
            <Box className="w-5 h-5" />
          </div>
          <div>
            <div className="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
              {t("virtualizationMode")}
            </div>
            <div className="text-sm font-semibold text-zinc-900 dark:text-zinc-100 capitalize">
              {vm.instance_type === "virtual-machine" ? "KVM Machine" : "Container"}
            </div>
          </div>
        </div>
      </div>

      {/* Network Configuration Block */}
      <div className="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30 overflow-hidden">
        <div className="px-5 py-3.5 bg-zinc-100/70 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-800 flex items-center justify-between">
          <div className="flex items-center space-x-2 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
            <Globe className="w-4 h-4 text-blue-500" />
            <span>Network Configuration</span>
          </div>
          <div>{getNetworkStatusBadge(netStatus)}</div>
        </div>
        <div className="p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-y-4 gap-x-6 text-xs">
          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("colIp")}</div>
            <div className="font-mono text-sm font-semibold text-zinc-900 dark:text-zinc-100">
              {primaryIp}
            </div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("macAddress")}</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200">{macAddress}</div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("networkMode")}</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200 uppercase">
              {netMode}
            </div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("networkGateway")}</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200">{gateway}</div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("networkDns")}</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200">{dnsServers}</div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("networkStatus")}</div>
            <div className="font-medium text-zinc-800 dark:text-zinc-200 capitalize">
              {netStatus}
            </div>
          </div>
        </div>
      </div>

      {/* Host & Platform Metadata */}
      <div className="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
        <div className="px-5 py-3.5 bg-zinc-50 dark:bg-zinc-800/40 border-b border-zinc-200 dark:border-zinc-800 flex items-center space-x-2 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
          <Server className="w-4 h-4 text-emerald-500" />
          <span>Infrastructure & Placement</span>
        </div>
        <div className="p-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-y-4 gap-x-6 text-xs">
          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("hostNode")}</div>
            <div className="font-medium text-zinc-800 dark:text-zinc-200 flex items-center space-x-1.5">
              <Server className="w-3.5 h-3.5 text-zinc-400" />
              <span>{vm.host?.name || (vm.host_id ? `Host #${vm.host_id}` : "Auto-assigned")}</span>
            </div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">Incus Project</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200 flex items-center space-x-1.5">
              <Shield className="w-3.5 h-3.5 text-zinc-400" />
              <span>{vm.incus_project}</span>
            </div>
          </div>

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("osImage")}</div>
            <div className="font-mono text-zinc-800 dark:text-zinc-200">
              {vm.os_image.replace("images:", "")}
            </div>
          </div>

          {isAdmin && vm.user && (
            <div>
              <div className="text-zinc-400 font-medium mb-1">{t("owner")}</div>
              <div className="font-medium text-zinc-800 dark:text-zinc-200 flex items-center space-x-1.5">
                <UserIcon className="w-3.5 h-3.5 text-zinc-400" />
                <span>
                  {vm.user.name} ({vm.user.email})
                </span>
              </div>
            </div>
          )}

          <div>
            <div className="text-zinc-400 font-medium mb-1">{t("created")}</div>
            <div className="text-zinc-800 dark:text-zinc-200 flex items-center space-x-1.5">
              <Clock className="w-3.5 h-3.5 text-zinc-400" />
              <span>{new Date(vm.created_at).toLocaleString()}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
