"use client";

import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from "react";
import { api, User, AdminUserItem, SystemSettings } from "@/lib/api";
import { clearAllCachedFetchEntries } from "@/lib/hooks/useCachedFetch";
import { disconnectEcho } from "@/lib/echo";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";
import {
  ImpersonatedUserInfo,
  cleanupLegacyStorage,
  startImpersonationStorage,
  stopImpersonationStorage,
  clearAllAuthStorage,
  getImpersonatedUserMetadata,
} from "./impersonationStorage";

export type { ImpersonatedUserInfo };

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
  startImpersonation: (targetUser: AdminUserItem) => Promise<void>;
  stopImpersonation: () => Promise<boolean>;
  handleAuthSuccess: (loggedInUser: User, userRoles?: string[]) => void;
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

  const [impersonatedUserState, setImpersonatedUserState] = useState<ImpersonatedUserInfo | null>(null);
  const [impersonatingState, setImpersonatingState] = useState<boolean>(false);
  const [isStoppingImpersonation, setIsStoppingImpersonation] = useState<boolean>(false);

  const isAdmin =
    (user?.roles?.some((r) => r.name === "admin") ?? false) ||
    roles.includes("admin");

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

    async function checkAuth() {
      try {
        cleanupLegacyStorage();
        const { user: currentUser, roles: currentRoles } = await api.getMe();
        if (!isMounted) return;
        setUser(currentUser);
        setRoles(currentRoles);
        const stored = getImpersonatedUserMetadata();
        if (stored) {
          setImpersonatedUserState(stored);
          setImpersonatingState(true);
        }
      } catch {
        if (!isMounted) return;
        clearAllAuthStorage();
        setUser(null);
        setRoles([]);
        setImpersonatedUserState(null);
        setImpersonatingState(false);
      } finally {
        if (isMounted) {
          setAuthLoading(false);
        }
      }
    }

    checkAuth();

    return () => {
      isMounted = false;
    };
  }, []);

  const startImpersonation = useCallback(
    async (targetUser: AdminUserItem) => {
      const res = await api.adminImpersonateUser(targetUser.id);

      startImpersonationStorage(res.token, {
        id: targetUser.id,
        name: targetUser.name,
        email: targetUser.email,
      });

      disconnectEcho();

      setImpersonatedUser({
        id: targetUser.id,
        name: targetUser.name,
        email: targetUser.email,
      });

      toast.success(res.message || t("impersonationStarted"));

      // Refresh session to reflect impersonated user state and permissions
      const { user: newUser, roles: newRoles } = await api.getMe();
      clearAllCachedFetchEntries();
      setUser(newUser);
      setRoles(newRoles);
    },
    [setImpersonatedUser, toast, t]
  );

  const stopImpersonation = useCallback(async (): Promise<boolean> => {
    setIsStoppingImpersonation(true);
    try {
      const stopRes = await api.stopImpersonation();
      if (!stopRes?.token) {
        throw new Error("No admin token returned from server");
      }

      stopImpersonationStorage(stopRes.token);
      disconnectEcho();
      clearAllCachedFetchEntries();
      setImpersonatedUser(null);
      setIsStoppingImpersonation(false);
      toast.info(stopRes.message || t("impersonationEnded"));

      // Refresh session to reflect restored admin
      const { user: restoredUser, roles: restoredRoles } = await api.getMe();
      clearAllCachedFetchEntries();
      setUser(restoredUser);
      setRoles(restoredRoles);
      return true;
    } catch (err) {
      console.warn("Failed to stop impersonation:", err);
      clearAllAuthStorage();
      disconnectEcho();
      clearAllCachedFetchEntries();
      setImpersonatedUser(null);
      setUser(null);
      setRoles([]);
      setIsStoppingImpersonation(false);
      toast.error(t("sessionExpired"));
      if (typeof window !== "undefined") {
        window.history.pushState(null, "", "/");
      }
      return false;
    }
  }, [setImpersonatedUser, toast, t]);

  const handleAuthSuccess = useCallback(
    (loggedInUser: User, userRoles?: string[]) => {
      disconnectEcho();
      clearAllCachedFetchEntries();
      const resolvedRoles =
        userRoles ?? loggedInUser.roles?.map((r) => r.name) ?? [];
      setUser(loggedInUser);
      setRoles(resolvedRoles);
      if (typeof window !== "undefined") {
        window.history.pushState(null, "", "/instances");
      }
      toast.success(`Welcome back, ${loggedInUser.name}!`);
    },
    [toast]
  );

  const handleLogout = useCallback(async () => {
    disconnectEcho();
    try {
      await api.logout();
    } finally {
      clearAllCachedFetchEntries();
      clearAllAuthStorage();
      setImpersonatedUserState(null);
      setImpersonatingState(false);
      setUser(null);
      setRoles([]);
      if (typeof window !== "undefined") {
        window.history.pushState(null, "", "/");
      }
      toast.info("You have signed out.");
    }
  }, [toast]);

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
