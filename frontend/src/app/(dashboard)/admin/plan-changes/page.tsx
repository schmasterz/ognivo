"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth/AuthContext";
import { AdminPlanChangesView } from "@/components/plans/AdminPlanChangesView";

export default function AdminPlanChangesPage() {
  const router = useRouter();
  const { user, isAdmin, authLoading } = useAuth();

  useEffect(() => {
    if (!authLoading && user && !isAdmin) {
      router.replace("/instances");
    }
  }, [authLoading, user, isAdmin, router]);

  if (!isAdmin) {
    return null;
  }

  return <AdminPlanChangesView />;
}
