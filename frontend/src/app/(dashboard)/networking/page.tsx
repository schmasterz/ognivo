"use client";

import React from "react";
import { NetworkingTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export default function NetworkingPage() {
  const { isAdmin } = useAuth();
  const { perPage } = useGlobalFilters();
  const {
    ips,
    pools,
    loadingIps,
    ipsMeta,
    setIpsPage,
    poolsMeta,
    poolsPerPage,
    setPoolsPage,
    setPoolsPerPage,
    refetchPools,
    handlePerPageChange,
    modals,
    handleRequestReleaseIp,
    handleRequestDeletePool,
  } = useDashboard();

  return (
    <NetworkingTab
      ips={ips}
      pools={pools}
      loading={loadingIps}
      isAdmin={isAdmin}
      onRequestRelease={handleRequestReleaseIp}
      onAddPool={() => modals.setCreatePoolModalOpen(true)}
      onRequestDeletePool={handleRequestDeletePool}
      onPoolUpdated={refetchPools}
      ipsPagination={{
        currentPage: ipsMeta.current_page,
        lastPage: ipsMeta.last_page,
        perPage,
        total: ipsMeta.total,
        onPageChange: setIpsPage,
        onPerPageChange: handlePerPageChange,
      }}
      poolsPagination={{
        currentPage: poolsMeta.current_page,
        lastPage: poolsMeta.last_page,
        perPage: poolsPerPage,
        total: poolsMeta.total,
        onPageChange: setPoolsPage,
        onPerPageChange: setPoolsPerPage,
      }}
    />
  );
}
