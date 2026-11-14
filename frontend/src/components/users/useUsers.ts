"use client";

import { useState, useCallback, useMemo, useEffect } from "react";
import { api } from "@/lib/api";
import { useCachedFetch } from "@/lib/hooks/useCachedFetch";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

export interface UseUsersParams {
  sessionUserId?: number | null;
  userId?: number | null;
  hostId?: number | null;
  status?: string | null;
  search?: string | null;
  perPage?: number;
  enabled?: boolean;
  initialRegistrationEnabled?: boolean;
}

export function useUsers({
  sessionUserId = null,
  userId = null,
  hostId = null,
  status = null,
  search = null,
  perPage = 10,
  enabled = true,
  initialRegistrationEnabled = true,
}: UseUsersParams = {}) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [page, setPage] = useState<number>(1);
  const [registrationEnabled, setRegistrationEnabled] = useState<boolean>(initialRegistrationEnabled);

  useEffect(() => {
    queueMicrotask(() => {
      setRegistrationEnabled(initialRegistrationEnabled);
    });
  }, [initialRegistrationEnabled]);

  // Reset page when filters change
  useEffect(() => {
    queueMicrotask(() => {
      setPage(1);
    });
  }, [sessionUserId, userId, hostId, status, search]);

  const cacheKey = useMemo(() => {
    if (!enabled) return null;
    return `uSession:${sessionUserId ?? "anon"}:admin-users:u${userId ?? ""}:h${hostId ?? ""}:s${status ?? ""}:q${search ?? ""}:p${page}:pp${perPage}`;
  }, [enabled, sessionUserId, userId, hostId, status, search, page, perPage]);

  const fetcher = useCallback(
    (signal: AbortSignal) =>
      api.getAdminUsers(
        {
          user_id: userId,
          host_id: hostId,
          status: status || null,
          search: search?.trim() || null,
          page,
          per_page: perPage,
        },
        { signal }
      ),
    [userId, hostId, status, search, page, perPage]
  );

  const {
    data: usersRes,
    loading,
    refetch,
    clearCache,
  } = useCachedFetch(cacheKey, fetcher, {
    enabled,
    ttlMs: 15000,
  });

  const users = useMemo(() => usersRes?.data ?? [], [usersRes]);

  const meta = useMemo(() => {
    return {
      current_page: usersRes?.meta?.current_page ?? usersRes?.current_page ?? page,
      last_page: usersRes?.meta?.last_page ?? usersRes?.last_page ?? 1,
      total: usersRes?.meta?.total ?? usersRes?.total ?? users.length,
    };
  }, [usersRes, page, users.length]);

  const toggleRegistration = useCallback(
    async (nextEnabled: boolean) => {
      try {
        const res = await api.updateAdminSettings({ registration_enabled: nextEnabled });
        setRegistrationEnabled(res.settings.registration_enabled);
        toast.success(
          res.settings.registration_enabled
            ? t("registrationEnabled")
            : t("registrationDisabled")
        );
      } catch (err: unknown) {
        if (err instanceof Error) {
          toast.error(err.message);
        }
      }
    },
    [toast, t]
  );

  return {
    users,
    loading,
    page,
    setPage,
    meta,
    refetch,
    clearCache,
    registrationEnabled,
    setRegistrationEnabled,
    toggleRegistration,
  };
}
