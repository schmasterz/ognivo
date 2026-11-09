"use client";

import React from "react";
import { VmsTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export default function InstancesPage() {
  const { isAdmin } = useAuth();
  const { perPage } = useGlobalFilters();
  const {
    vms,
    loadingVms,
    vmsPage,
    vmsMeta,
    setVmsPage,
    handlePerPageChange,
    modals,
    handleRequestVmAction,
    handleSetDetailsVm,
  } = useDashboard();

  return (
    <VmsTab
      vms={vms}
      loading={loadingVms}
      isAdmin={isAdmin}
      currentPage={vmsPage}
      lastPage={vmsMeta.last_page}
      perPage={perPage}
      total={vmsMeta.total}
      onPageChange={setVmsPage}
      onPerPageChange={handlePerPageChange}
      onDeployInstance={() => modals.setCreateModalOpen(true)}
      onRequestAction={handleRequestVmAction}
      onOpenConsole={(vm) => modals.setConsoleVm(vm)}
      onOpenDetails={(vm, initialTab) => handleSetDetailsVm({ vm, initialTab })}
    />
  );
}
