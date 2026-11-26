"use client";

import React, {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  useMemo,
  useRef,
} from "react";
import { api, User, AdminUserItem, SystemSettings, MeResponse } from "@/lib/api";
import { clearAllCachedFetchEntries } from "@/lib/hooks/useCachedFetch";
import {
  disconnectEcho,
  enableEcho,
  scheduleEchoDisconnectAt,
} from "@/lib/echo";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import { purgeLegacyAuthStorage } from "./legacyStoragePurge";

export interface ImpersonatedUserInfo {
  id: number;
  name: string;
  email: string;
}

const MAX_TIMEOUT_MS = 2_147_483_647;

export interface AuthContextValue {
  user: User | null;
  setUser: React.Dispatch<React.SetStateAction<User | null>>;
  roles: string[];
  setRoles: React.Dispatch<React.SetStateAction<string[]>>;
  isAdmin: boolean;
  authLoading: boolean;
  registrationEnabled: boolean;
  setRegistrationEnabled: (enabled: boolean) => void;
  allowVm: boolean;
  setAllowVm: (allowed: boolean) => void;
  allowContainers: boolean;
  setAllowContainers: (allowed: boolean) => void;
  systemSettings: SystemSettings;
  setSystemSettings: (settings: SystemSettings) => void;
  impersonating: boolean;
  setImpersonating: (impersonating: boolean) => void;
  impersonatedUser: ImpersonatedUserInfo | null;
  setImpersonatedUser: (target: ImpersonatedUserInfo | null) => void;
  isStoppingImpersonation: boolean;
  setIsStoppingImpersonation: React.Dispatch<React.SetStateAction<boolean>>;
  refreshAuth: () => Promise<MeResponse>;
  startImpersonation: (targetUser: AdminUserItem) => Promise<void>;
  stopImpersonation: () => Promise<boolean>;
  handleAuthSuccess: (
    loggedInUser: User,
    userRoles?: string[],
    sessionExpiresAt?: string | null
  ) => void;
  handleLogout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const { t } = useI18n();
  const { toast } = useToast();

  const [user, setUser] = useState<User | null>(null);
  const [roles, setRoles] = useState<string[]>([]);
  const [authLoading, setAuthLoading] = useState<boolean>(true);

  const [systemSettingsState, setSystemSettingsState] = useState<SystemSettings>({
    registration_enabled: true,
    allow_virtual_machines: true,
    allow_containers: true,
  });
  const [registrationEnabledState, setRegistrationEnabledState] = useState<boolean>(true);
  const [allowVmState, setAllowVmState] = useState<boolean>(true);
  const [allowContainersState, setAllowContainersState] = useState<boolean>(true);

  const [impersonatedUserState, setImpersonatedUserState] =
    useState<ImpersonatedUserInfo | null>(null);
  const [impersonatingState, setImpersonatingState] = useState<boolean>(false);
  const [isStoppingImpersonation, setIsStoppingImpersonation] = useState<boolean>(false);

  const sessionExpiryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const isAdmin =
    (user?.roles?.some((r) => r.name === "admin") ?? false) ||
    roles.includes("admin");

  const clearSessionExpiryTimer = useCallback(() => {
    if (sessionExpiryTimerRef.current !== null) {
      clearTimeout(sessionExpiryTimerRef.current);
      sessionExpiryTimerRef.current = null;
    }
  }, []);

  const clearAuthSession = useCallback(() => {
    clearSessionExpiryTimer();
    disconnectEcho();
    clearAllCachedFetchEntries();
    setUser(null);
    setRoles([]);
    setImpersonatedUserState(null);
    setImpersonatingState(false);
  }, [clearSessionExpiryTimer]);

  const scheduleSessionExpiry = useCallback(
    (expiresAtIso: string | null) => {
      clearSessionExpiryTimer();

      if (!expiresAtIso) {
        return;
      }

      const expiresAtMs = Date.parse(expiresAtIso);
      if (!Number.isFinite(expiresAtMs)) {
        return;
      }

      const scheduleAt = (targetMs: number) => {
        const remainingMs = targetMs - Date.now();
        if (remainingMs <= 0) {
          clearAuthSession();
          return;
        }

        const waitMs = Math.min(remainingMs, MAX_TIMEOUT_MS);
        sessionExpiryTimerRef.current = setTimeout(() => {
          sessionExpiryTimerRef.current = null;
          if (Date.now() >= targetMs) {
            clearAuthSession();
          } else {
            scheduleAt(targetMs);
          }
        }, waitMs);
      };

      scheduleAt(expiresAtMs);
    },
    [clearAuthSession, clearSessionExpiryTimer]
  );

  const applyMeResponse = useCallback(
    (res: MeResponse) => {
      setUser(res.user);
      setRoles(res.roles);

      const impersonatedInfo: ImpersonatedUserInfo | null = res.impersonation?.active
        ? {
            id: res.user.id,
            name: res.user.name,
            email: res.user.email,
          }
        : null;

      setImpersonatedUserState(impersonatedInfo);
      setImpersonatingState(Boolean(impersonatedInfo));

      enableEcho();
      scheduleEchoDisconnectAt(res.session_expires_at);
      scheduleSessionExpiry(res.session_expires_at);
    },
    [scheduleSessionExpiry]
  );

  const setSystemSettings = useCallback((settings: SystemSettings) => {
    setSystemSettingsState(settings);
    setRegistrationEnabledState(settings.registration_enabled);
    setAllowVmState(settings.allow_virtual_machines);
    setAllowContainersState(settings.allow_containers);
  }, []);

  const setRegistrationEnabled = useCallback((enabled: boolean) => {
    setRegistrationEnabledState(enabled);
    setSystemSettingsState((prev) => ({
      ...prev,
      registration_enabled: enabled,
    }));
  }, []);

  const setAllowVm = useCallback((allowed: boolean) => {
    setAllowVmState(allowed);
    setSystemSettingsState((prev) => ({
      ...prev,
      allow_virtual_machines: allowed,
    }));
  }, []);

  const setAllowContainers = useCallback((allowed: boolean) => {
    setAllowContainersState(allowed);
    setSystemSettingsState((prev) => ({
      ...prev,
      allow_containers: allowed,
    }));
  }, []);

  const setImpersonatedUser = useCallback((target: ImpersonatedUserInfo | null) => {
    setImpersonatedUserState(target);
    setImpersonatingState(Boolean(target));
  }, []);

  const setImpersonating = useCallback((nextImpersonating: boolean) => {
    setImpersonatingState(nextImpersonating);
    if (!nextImpersonating) {
      setImpersonatedUserState(null);
    }
  }, []);

  useEffect(() => {
    let isMounted = true;

    const handleUnauthenticated = () => {
      if (!isMounted) return;
      clearAuthSession();
    };

    if (typeof window !== "undefined") {
      window.addEventListener("ognivo:unauthenticated", handleUnauthenticated);
    }

    async function checkAuth() {
      try {
        purgeLegacyAuthStorage();
        const me = await api.getMe();
        if (!isMounted) return;
        applyMeResponse(me);
      } catch {
        if (!isMounted) return;
        clearAuthSession();
      } finally {
        if (isMounted) {
          setAuthLoading(false);
        }
      }
    }

    checkAuth();

    return () => {
      isMounted = false;
      clearSessionExpiryTimer();
      if (typeof window !== "undefined") {
        window.removeEventListener("ognivo:unauthenticated", handleUnauthenticated);
      }
    };
  }, [applyMeResponse, clearAuthSession, clearSessionExpiryTimer]);

  const refreshAuth = useCallback(async (): Promise<MeResponse> => {
    const me = await api.getMe();
    applyMeResponse(me);
    return me;
  }, [applyMeResponse]);

  const startImpersonation = useCallback(
    async (targetUser: AdminUserItem) => {
      const res = await api.adminImpersonateUser(targetUser.id);

      disconnectEcho();
      clearAllCachedFetchEntries();
      clearSessionExpiryTimer();

      const me = await api.getMe();
      applyMeResponse(me);

      toast.success(res.message || t("impersonationStarted"));
    },
    [applyMeResponse, clearSessionExpiryTimer, toast, t]
  );

  const stopImpersonation = useCallback(async (): Promise<boolean> => {
    setIsStoppingImpersonation(true);
    try {
      const stopRes = await api.stopImpersonation();

      disconnectEcho();
      clearAllCachedFetchEntries();
      clearSessionExpiryTimer();

      const me = await api.getMe();
      applyMeResponse(me);
      setIsStoppingImpersonation(false);
      toast.info(stopRes.message || t("impersonationEnded"));
      return true;
    } catch (err) {
      console.warn("Failed to stop impersonation:", err);
      clearAuthSession();
      setIsStoppingImpersonation(false);
      toast.error(t("sessionExpired"));
      if (typeof window !== "undefined") {
        window.history.pushState(null, "", "/");
      }
      return false;
    }
  }, [applyMeResponse, clearAuthSession, clearSessionExpiryTimer, toast, t]);

  const handleAuthSuccess = useCallback(
    (
      loggedInUser: User,
      userRoles?: string[],
      sessionExpiresAt?: string | null
    ) => {
      disconnectEcho();
      clearAllCachedFetchEntries();
      clearSessionExpiryTimer();

      const resolvedRoles =
        userRoles ?? loggedInUser.roles?.map((r) => r.name) ?? [];
      setUser(loggedInUser);
      setRoles(resolvedRoles);
      setImpersonatedUserState(null);
      setImpersonatingState(false);

      enableEcho();
      if (sessionExpiresAt !== undefined) {
        scheduleEchoDisconnectAt(sessionExpiresAt);
        scheduleSessionExpiry(sessionExpiresAt);
      } else {
        void refreshAuth().catch(() => {});
      }

      toast.success(`Welcome back, ${loggedInUser.name}!`);
    },
    [clearSessionExpiryTimer, refreshAuth, scheduleSessionExpiry, toast]
  );

  const handleLogout = useCallback(async () => {
    disconnectEcho();
    clearSessionExpiryTimer();
    try {
      await api.logout();
    } finally {
      clearAuthSession();
      if (typeof window !== "undefined") {
        window.history.pushState(null, "", "/");
      }
      toast.info("You have signed out.");
    }
  }, [clearAuthSession, clearSessionExpiryTimer, toast]);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      setUser,
      roles,
      setRoles,
      isAdmin,
      authLoading,
      registrationEnabled: registrationEnabledState,
      setRegistrationEnabled,
      allowVm: allowVmState,
      setAllowVm,
      allowContainers: allowContainersState,
      setAllowContainers,
      systemSettings: systemSettingsState,
      setSystemSettings,
      impersonating: impersonatingState,
      setImpersonating,
      impersonatedUser: impersonatedUserState,
      setImpersonatedUser,
      isStoppingImpersonation,
      setIsStoppingImpersonation,
      refreshAuth,
      startImpersonation,
      stopImpersonation,
      handleAuthSuccess,
      handleLogout,
    }),
    [
      user,
      roles,
      isAdmin,
      authLoading,
      registrationEnabledState,
      setRegistrationEnabled,
      allowVmState,
      setAllowVm,
      allowContainersState,
      setAllowContainers,
      systemSettingsState,
      setSystemSettings,
      impersonatingState,
      setImpersonating,
      impersonatedUserState,
      setImpersonatedUser,
      isStoppingImpersonation,
      refreshAuth,
      startImpersonation,
      stopImpersonation,
      handleAuthSuccess,
      handleLogout,
    ]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return ctx;
}
