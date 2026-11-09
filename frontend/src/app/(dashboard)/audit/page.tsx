"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { AuditLogTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";

export default function AuditPage() {
  const router = useRouter();
  const { user, isAdmin, authLoading } = useAuth();
  const { handleAuditNavigate } = useDashboard();

  useEffect(() => {
    if (!authLoading && user && !isAdmin) {
      router.replace("/instances");
    }
  }, [authLoading, user, isAdmin, router]);

  if (!isAdmin) {
    return null;
  }

  return <AuditLogTab isAdmin={true} onNavigate={handleAuditNavigate} />;
}
