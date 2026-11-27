"use client";

import React, { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth/AuthContext";
import { AdminPlansView } from "@/components/plans/AdminPlansView";

export default function AdminPlansPage() {
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

  return <AdminPlansView />;
}
