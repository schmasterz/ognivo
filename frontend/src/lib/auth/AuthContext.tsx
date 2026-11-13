"use client";

import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from "react";
import { api, User, AdminUserItem, SystemSettings } from "@/lib/api";
import { clearAllCachedFetchEntries } from "@/lib/hooks/useCachedFetch";
import { disconnectEcho } from "@/lib/echo";
import { useI18n } from "@/lib/i18n/I18nContext";
import { useToast } from "@/lib/toast/ToastContext";

export interface ImpersonatedUserInfo {
  id: number;
  name: string;
  email: string;
}

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
        const { user: currentUser, roles: currentRoles } = await api.getMe();
        if (!isMounted) return;
        setUser(currentUser);
        setRoles(currentRoles);
        if (typeof window !== "undefined") {
          localStorage.removeItem("admin_token_backup");
          const stored =
            sessionStorage.getItem("impersonated_user") ||
            localStorage.getItem("impersonated_user");
          if (stored) {
            try {
              const parsed = JSON.parse(stored) as ImpersonatedUserInfo;
              setImpersonatedUserState(parsed);
              setImpersonatingState(true);
            } catch {
              sessionStorage.removeItem("impersonated_user");
              localStorage.removeItem("impersonated_user");
            }
          } else if (sessionStorage.getItem("admin_token_backup")) {
            setImpersonatingState(true);
          }
        }
      } catch {
        if (!isMounted) return;
        if (typeof window !== "undefined") {
          const backup =
            sessionStorage.getItem("admin_token_backup") ||
            localStorage.getItem("admin_token_backup");
          if (backup) {
            localStorage.setItem("auth_token", backup);
            sessionStorage.removeItem("admin_token_backup");
            sessionStorage.removeItem("impersonated_user");
            localStorage.removeItem("admin_token_backup");
            localStorage.removeItem("impersonated_user");
            setImpersonatedUserState(null);
            setImpersonatingState(false);
            try {
              const { user: restoredUser, roles: restoredRoles } = await api.getMe();
              if (!isMounted) return;
              setUser(restoredUser);
              setRoles(restoredRoles);
              return;
            } catch {
              // Both tokens failed
            }
          }
          localStorage.removeItem("auth_token");
          sessionStorage.removeItem("admin_token_backup");
          sessionStorage.removeItem("impersonated_user");
          localStorage.removeItem("admin_token_backup");
          localStorage.removeItem("impersonated_user");
        }
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
      const currentAdminToken =
        typeof window !== "undefined" ? localStorage.getItem("auth_token") || "" : "";
      const res = await api.adminImpersonateUser(targetUser.id);

      if (typeof window !== "undefined") {
        sessionStorage.setItem("admin_token_backup", currentAdminToken);
        sessionStorage.setItem(
          "impersonated_user",
          JSON.stringify({
            id: targetUser.id,
            name: targetUser.name,
            email: targetUser.email,
          })
        );
        localStorage.removeItem("admin_token_backup");
        localStorage.removeItem("impersonated_user");
        localStorage.setItem("auth_token", res.token);
      }

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
    let stopRes: { message: string; admin_token?: string | null } | null = null;
    try {
      stopRes = await api.stopImpersonation().catch((err) => {
        console.warn("Failed to notify server of impersonation stop:", err);
        return null;
      });
    } finally {
      if (typeof window !== "undefined") {
        const restoredToken =
          stopRes?.admin_token ||
          sessionStorage.getItem("admin_token_backup") ||
          localStorage.getItem("admin_token_backup");
        if (restoredToken) {
          localStorage.setItem("auth_token", restoredToken);
        } else {
          localStorage.removeItem("auth_token");
        }
        sessionStorage.removeItem("admin_token_backup");
        sessionStorage.removeItem("impersonated_user");
        localStorage.removeItem("admin_token_backup");
        localStorage.removeItem("impersonated_user");
      }
      disconnectEcho();
      clearAllCachedFetchEntries();
      setImpersonatedUser(null);
      setIsStoppingImpersonation(false);
      toast.info(t("impersonationEnded"));
    }

    try {
      const { user: restoredUser, roles: restoredRoles } = await api.getMe();
      clearAllCachedFetchEntries();
      setUser(restoredUser);
      setRoles(restoredRoles);
      return true;
    } catch {
      setUser(null);
      setRoles([]);
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
      if (typeof window !== "undefined") {
        sessionStorage.removeItem("admin_token_backup");
        sessionStorage.removeItem("impersonated_user");
        localStorage.removeItem("admin_token_backup");
        localStorage.removeItem("impersonated_user");
      }
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
