"use client";

import { useState, useCallback, useEffect } from "react";
import {
  api,
  User,
  QuotaResponse,
  AdminStats,
  QuotaGroup,
  HostNode,
  SystemSettings,
} from "@/lib/api";

export interface UseDashboardDataParams {
  user: User | null;
  isAdmin: boolean;
  activeTab: string;
  selectedUserId: number | null;
  selectedHostId: number | null;
  selectedStatus: string | null;
  debouncedSearchQuery: string;
  setSystemSettings: (settings: SystemSettings) => void;
  setRegistrationEnabled: (enabled: boolean) => void;
  refetchVms: () => Promise<void>;
  refetchNetworking: () => Promise<void>;
  refetchUsers: () => Promise<void>;
}

export function useDashboardData({
  user,
  isAdmin,
  activeTab,
  selectedUserId,
  selectedHostId,
  selectedStatus,
  debouncedSearchQuery,
  setSystemSettings,
  setRegistrationEnabled,
  refetchVms,
  refetchNetworking,
  refetchUsers,
}: UseDashboardDataParams) {
  const [adminStats, setAdminStats] = useState<AdminStats | null>(null);
  const [quotaGroups, setQuotaGroups] = useState<QuotaGroup[]>([]);
  const [quotaData, setQuotaData] = useState<QuotaResponse | null>(null);
  const [hosts, setHosts] = useState<HostNode[]>([]);
  const [refreshing, setRefreshing] = useState(false);

  // Load static infrastructure and admin configuration once on mount or when changed
  const loadStaticData = useCallback(async () => {
    try {
      if (isAdmin) {
        const [groupsRes, hostsRes, settingsRes] = await Promise.all([
          api.getQuotaGroups({ per_page: 100 }),
          api.adminGetHosts({ per_page: 100 }),
          api.getAdminSettings(),
        ]);
        setQuotaGroups(groupsRes.data);
        setHosts(hostsRes.data);
        setSystemSettings(settingsRes.settings);
        setRegistrationEnabled(settingsRes.settings.registration_enabled);
      } else {
        const [hostsRes, publicSettingsRes] = await Promise.all([
          api.getHosts(),
          api.getPublicSettings().catch(() => null),
        ]);
        setHosts(hostsRes.data);
        if (publicSettingsRes) {
          setSystemSettings(publicSettingsRes);
          setRegistrationEnabled(publicSettingsRes.registration_enabled);
        }
      }
    } catch (err: unknown) {
      console.error("Error loading static configuration:", err);
    }
  }, [isAdmin, setRegistrationEnabled, setSystemSettings]);

  // Load Quota and Stats
  const loadQuotaAndStats = useCallback(async () => {
    try {
      const filters = {
        user_id: selectedUserId,
        host_id: selectedHostId,
        status: selectedStatus || null,
        search: debouncedSearchQuery.trim() || null,
      };

      const [quotaRes, statsRes] = await Promise.all([
        api.getQuota(filters),
        isAdmin ? api.getAdminStats(filters) : Promise.resolve(null),
      ]);

      setQuotaData(quotaRes);
      if (statsRes) {
        setAdminStats(statsRes.overview);
      }
    } catch (err: unknown) {
      console.error("Error loading quota/stats:", err);
    }
  }, [
    selectedUserId,
    selectedHostId,
    selectedStatus,
    debouncedSearchQuery,
    isAdmin,
  ]);

  const loadData = useCallback(async () => {
    setRefreshing(true);
    try {
      await Promise.all([
        loadStaticData(),
        loadQuotaAndStats(),
        refetchVms(),
        activeTab === "networking" ? refetchNetworking() : Promise.resolve(),
        isAdmin && activeTab === "users" ? refetchUsers() : Promise.resolve(),
      ]);
    } finally {
      setRefreshing(false);
    }
  }, [
    loadStaticData,
    loadQuotaAndStats,
    refetchVms,
    activeTab,
    refetchNetworking,
    isAdmin,
    refetchUsers,
  ]);

  // Fetch static metadata when user session becomes active
  useEffect(() => {
    if (!user) return;
    const timer = setTimeout(() => {
      void loadStaticData();
    }, 0);
    return () => clearTimeout(timer);
  }, [user, loadStaticData]);

  // Fetch and resync quota/stats when user session or global filters change
  useEffect(() => {
    if (!user) return;
    const timer = setTimeout(() => {
      void loadQuotaAndStats();
    }, 0);
    return () => clearTimeout(timer);
  }, [user, loadQuotaAndStats]);

  return {
    adminStats,
    quotaGroups,
    quotaData,
    setQuotaData,
    hosts,
    setHosts,
    refreshing,
    loadStaticData,
    loadQuotaAndStats,
    loadData,
  };
}
