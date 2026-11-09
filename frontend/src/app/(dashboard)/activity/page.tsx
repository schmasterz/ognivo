"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { AuditLogTab, useDashboard } from "@/components";
import { useAuth } from "@/lib/auth/AuthContext";

export default function ActivityPage() {
  const router = useRouter();
  const { user, isAdmin, authLoading } = useAuth();
  const { handleAuditNavigate } = useDashboard();

  useEffect(() => {
    if (!authLoading && user && isAdmin) {
      router.replace("/audit");
    }
  }, [authLoading, user, isAdmin, router]);

  return <AuditLogTab isAdmin={isAdmin} onNavigate={handleAuditNavigate} />;
}
