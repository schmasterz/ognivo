"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { NodesTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";

export default function NodesPage() {
  const router = useRouter();
  const { user, isAdmin, authLoading } = useAuth();
  const { hosts, loadStaticData, modals, handleRequestDeleteHost } = useDashboard();

  useEffect(() => {
    if (!authLoading && user && !isAdmin) {
      router.replace("/instances");
    }
  }, [authLoading, user, isAdmin, router]);

  if (!isAdmin) {
    return null;
  }

  return (
    <NodesTab
      initialHosts={hosts}
      onAddNode={() => modals.setCreateHostModalOpen(true)}
      onEditNode={(host) => modals.setEditHostTarget(host)}
      onDeleteNode={handleRequestDeleteHost}
      onRefreshHosts={loadStaticData}
    />
  );
}
