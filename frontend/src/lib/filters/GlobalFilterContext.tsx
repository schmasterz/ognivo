"use client";

import React, { createContext, useContext, useState, useEffect, useCallback, useMemo } from "react";

export interface GlobalFilterContextValue {
  selectedUserId: number | null;
  setSelectedUserId: React.Dispatch<React.SetStateAction<number | null>>;
  selectedHostId: number | null;
  setSelectedHostId: React.Dispatch<React.SetStateAction<number | null>>;
  selectedStatus: string | null;
  setSelectedStatus: React.Dispatch<React.SetStateAction<string | null>>;
  searchQuery: string;
  setSearchQuery: React.Dispatch<React.SetStateAction<string>>;
  debouncedSearchQuery: string;
  perPage: number;
  setPerPage: React.Dispatch<React.SetStateAction<number>>;
  hasActiveFilters: boolean;
  resetFilters: () => void;
}

const GlobalFilterContext = createContext<GlobalFilterContextValue | null>(null);

export function GlobalFilterProvider({ children }: { children: React.ReactNode }) {
  const [selectedUserId, setSelectedUserId] = useState<number | null>(null);
  const [selectedHostId, setSelectedHostId] = useState<number | null>(null);
  const [selectedStatus, setSelectedStatus] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState<string>("");
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState<string>("");
  const [perPage, setPerPage] = useState<number>(10);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearchQuery(searchQuery);
    }, 300);
    return () => clearTimeout(handler);
  }, [searchQuery]);

  const hasActiveFilters = Boolean(
    selectedUserId !== null ||
      selectedHostId !== null ||
      Boolean(selectedStatus && selectedStatus.trim()) ||
      Boolean(searchQuery.trim())
  );

  const resetFilters = useCallback(() => {
    setSelectedUserId(null);
    setSelectedHostId(null);
    setSelectedStatus(null);
    setSearchQuery("");
    setDebouncedSearchQuery("");
  }, []);

  const value = useMemo<GlobalFilterContextValue>(
    () => ({
      selectedUserId,
      setSelectedUserId,
      selectedHostId,
      setSelectedHostId,
      selectedStatus,
      setSelectedStatus,
      searchQuery,
      setSearchQuery,
      debouncedSearchQuery,
      perPage,
      setPerPage,
      hasActiveFilters,
      resetFilters,
    }),
    [
      selectedUserId,
      selectedHostId,
      selectedStatus,
      searchQuery,
      debouncedSearchQuery,
      perPage,
      hasActiveFilters,
      resetFilters,
    ]
  );

  return (
    <GlobalFilterContext.Provider value={value}>
      {children}
    </GlobalFilterContext.Provider>
  );
}

export function useGlobalFilters(): GlobalFilterContextValue {
  const ctx = useContext(GlobalFilterContext);
  if (!ctx) {
    throw new Error("useGlobalFilters must be used within a GlobalFilterProvider");
  }
  return ctx;
}
