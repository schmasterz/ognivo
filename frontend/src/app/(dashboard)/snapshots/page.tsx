"use client";

import React from "react";
import { SnapshotsTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";
import { useGlobalFilters } from "@/lib/filters/GlobalFilterContext";

export default function SnapshotsPage() {
  const { user, isAdmin } = useAuth();
  const { selectedUserId } = useGlobalFilters();
  const { vms, handleSetDetailsVm } = useDashboard();

  return (
    <SnapshotsTab
      isAdmin={isAdmin}
      currentUser={user}
      vms={vms}
      selectedUserId={selectedUserId}
      onOpenVmDetails={(vm, initialTab) =>
        handleSetDetailsVm({ vm, initialTab })
      }
    />
  );
}
