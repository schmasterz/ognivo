"use client";

import React, { useState, useRef, useEffect, useMemo, useCallback } from "react";
import { Users, X, Check, ChevronDown, Loader2 } from "lucide-react";
import { api, AdminUserItem } from "@/lib/api";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface UserOptionItem {
  id: number;
  name: string;
  email: string;
  roles?: Array<{ name: string }>;
  virtual_machines_count?: number;
}

interface SearchableUserSelectProps {
  users?: AdminUserItem[] | UserOptionItem[];
  selectedUserId: string;
  onSelectUser: (userId: string) => void;
  className?: string;
}

const MAX_VISIBLE_USERS = 8;

export function SearchableUserSelect({
  users,
  selectedUserId,
  onSelectUser,
  className = "",
}: SearchableUserSelectProps) {
  const { t } = useI18n();
  const [isOpen, setIsOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [highlightedIndex, setHighlightedIndex] = useState<number>(0);
  const [loading, setLoading] = useState(false);
  const [serverUsers, setServerUsers] = useState<UserOptionItem[]>([]);
  const [selectedUserRecord, setSelectedUserRecord] = useState<UserOptionItem | null>(null);

  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  // Synchronize selectedUserRecord when selectedUserId changes or users prop changes
  useEffect(() => {
    if (!selectedUserId) {
      setSelectedUserRecord(null);
      return;
    }

    if (users && users.length > 0) {
      const match = users.find((u) => String(u.id) === selectedUserId);
      if (match) {
        setSelectedUserRecord(match);
        return;
      }
    }

    // If we have no record for the current selectedUserId, fetch it
    if (!selectedUserRecord || String(selectedUserRecord.id) !== selectedUserId) {
      api.searchAdminUsers(undefined, 50)
        .then((res) => {
          const match = res.data.find((u) => String(u.id) === selectedUserId);
          if (match) {
            setSelectedUserRecord(match);
          }
        })
        .catch(() => {});
    }
  }, [selectedUserId, users, selectedUserRecord]);

  // Fetch users from server-side search endpoint with debounce
  const searchUsers = useCallback(async (query: string) => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    abortControllerRef.current = new AbortController();
    const signal = abortControllerRef.current.signal;

    setLoading(true);
    try {
      const res = await api.searchAdminUsers(query.trim() || undefined, MAX_VISIBLE_USERS, { signal });
      setServerUsers(res.data);
    } catch (err: unknown) {
      if (err instanceof Error && err.name === "AbortError") {
        return;
      }
      // If error occurs, fallback to client-filtered prop users if present
      if (users && users.length > 0) {
        const q = query.trim().toLowerCase();
        setServerUsers(
          users
            .filter((u) => !q || (u.email || "").toLowerCase().includes(q) || (u.name || "").toLowerCase().includes(q))
            .slice(0, MAX_VISIBLE_USERS)
        );
      }
    } finally {
      setLoading(false);
    }
  }, [users]);

  // Debounce search query
  useEffect(() => {
    if (!isOpen) return;

    const timer = setTimeout(() => {
      searchUsers(searchQuery);
    }, 250);

    return () => clearTimeout(timer);
  }, [searchQuery, isOpen, searchUsers]);

  // Display options: server results or prop users
  const displayedOptions = useMemo(() => {
    if (serverUsers.length > 0) {
      return serverUsers;
    }
    if (users && users.length > 0 && !loading) {
      const q = searchQuery.trim().toLowerCase();
      return users
        .filter((u) => !q || (u.email || "").toLowerCase().includes(q) || (u.name || "").toLowerCase().includes(q))
        .slice(0, MAX_VISIBLE_USERS);
    }
    return serverUsers;
  }, [serverUsers, users, searchQuery, loading]);

  // Close dropdown on outside click
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false);
        setSearchQuery("");
      }
    }

    if (isOpen) {
      document.addEventListener("mousedown", handleClickOutside);
    }
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [isOpen]);

  const handleSelect = (userId: string, userItem?: UserOptionItem) => {
    if (userItem) {
      setSelectedUserRecord(userItem);
    } else if (!userId) {
      setSelectedUserRecord(null);
    } else {
      const found = displayedOptions.find((u) => String(u.id) === userId);
      if (found) setSelectedUserRecord(found);
    }

    onSelectUser(userId);
    setIsOpen(false);
    setSearchQuery("");
  };

  const handleClear = (e?: React.MouseEvent) => {
    if (e) e.stopPropagation();
    setSelectedUserRecord(null);
    onSelectUser("");
    setSearchQuery("");
    inputRef.current?.focus();
  };

  const handleFocus = () => {
    setIsOpen(true);
    if (serverUsers.length === 0) {
      searchUsers("");
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!isOpen) {
      if (e.key === "ArrowDown" || e.key === "Enter") {
        setIsOpen(true);
        searchUsers("");
        e.preventDefault();
      }
      return;
    }

    if (e.key === "ArrowDown") {
      e.preventDefault();
      setHighlightedIndex((prev) =>
        prev < displayedOptions.length ? prev + 1 : 0
      );
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setHighlightedIndex((prev) =>
        prev > 0 ? prev - 1 : displayedOptions.length
      );
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (highlightedIndex === 0) {
        handleSelect("");
      } else {
        const target = displayedOptions[highlightedIndex - 1];
        if (target) {
          handleSelect(String(target.id), target);
        }
      }
    } else if (e.key === "Escape") {
      setIsOpen(false);
      setSearchQuery("");
    }
  };

  const displayedValue = isOpen
    ? searchQuery
    : selectedUserRecord
    ? `${selectedUserRecord.email} (${selectedUserRecord.name})`
    : "";

  const placeholderText = isOpen
    ? selectedUserRecord
      ? `${selectedUserRecord.email} (${selectedUserRecord.name})`
      : t("searchUserPlaceholder")
    : t("allTenants");

  return (
    <div ref={containerRef} className={`relative z-40 ${className}`}>
      {/* Combobox Direct Input Field */}
      <div className="relative flex items-center">
        <Users className="w-4 h-4 text-gray-400 absolute left-3 pointer-events-none transition-colors" />

        <input
          ref={inputRef}
          type="text"
          value={displayedValue}
          onChange={(e) => {
            setSearchQuery(e.target.value);
            if (!isOpen) setIsOpen(true);
            setHighlightedIndex(1);
          }}
          onFocus={handleFocus}
          onClick={() => {
            if (!isOpen) {
              setIsOpen(true);
              if (serverUsers.length === 0) {
                searchUsers(searchQuery);
              }
            }
          }}
          onKeyDown={handleKeyDown}
          placeholder={placeholderText}
          className="w-full bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl px-3.5 py-2 pl-9 pr-14 text-xs text-gray-900 dark:text-gray-100 placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:border-blue-500 shadow-sm dark:shadow-none transition font-medium"
        />

        <div className="absolute right-2.5 flex items-center space-x-1">
          {loading && (
            <Loader2 className="w-3.5 h-3.5 text-blue-500 animate-spin mr-1" />
          )}

          {(selectedUserId || searchQuery) && (
            <button
              type="button"
              onClick={handleClear}
              title={t("clearFilters")}
              className="p-1 rounded-md text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          )}

          <button
            type="button"
            onClick={() => {
              if (isOpen) {
                setIsOpen(false);
                setSearchQuery("");
              } else {
                inputRef.current?.focus();
                setIsOpen(true);
                if (serverUsers.length === 0) {
                  searchUsers(searchQuery);
                }
              }
            }}
            tabIndex={-1}
            className="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
          >
            <ChevronDown
              className={`w-3.5 h-3.5 transition-transform duration-200 ${
                isOpen ? "rotate-180 text-blue-500" : ""
              }`}
            />
          </button>
        </div>
      </div>

      {/* Dropdown Options List */}
      {isOpen && (
        <div className="absolute top-full left-0 w-full min-w-[280px] sm:min-w-[340px] mt-1.5 z-50 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-2xl overflow-hidden">
          <div className="max-h-96 overflow-y-auto p-1.5 space-y-0.5 divide-y divide-gray-50 dark:divide-gray-800/30">
            {/* "All Tenants" Option */}
            <button
              type="button"
              onClick={() => handleSelect("")}
              className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs text-left transition ${
                !selectedUserId
                  ? "bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 font-semibold"
                  : highlightedIndex === 0
                  ? "bg-gray-100 dark:bg-gray-800/80 text-gray-900 dark:text-white"
                  : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/60"
              }`}
            >
              <div className="flex items-center space-x-2.5">
                <Users className="w-3.5 h-3.5 text-gray-400" />
                <span>{t("allTenants")}</span>
              </div>
              {!selectedUserId && (
                <Check className="w-4 h-4 text-blue-600 dark:text-blue-400" />
              )}
            </button>

            {/* Server-searched user options */}
            {displayedOptions.map((u, idx) => {
              const isSelected = selectedUserId === String(u.id);
              const isHighlighted = highlightedIndex === idx + 1;
              const isAdmin = u.roles?.some((r) => r.name === "admin");

              return (
                <button
                  key={u.id}
                  type="button"
                  onClick={() => handleSelect(String(u.id), u)}
                  className={`w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs text-left transition ${
                    isSelected
                      ? "bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 font-semibold"
                      : isHighlighted
                      ? "bg-gray-100 dark:bg-gray-800/80 text-gray-900 dark:text-white"
                      : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800/60"
                  }`}
                >
                  <div className="flex flex-col min-w-0 pr-2">
                    <div className="flex items-center space-x-2">
                      <span className="font-mono font-semibold text-gray-900 dark:text-gray-100 truncate">
                        {u.email}
                      </span>
                      {isAdmin && (
                        <span className="text-[10px] uppercase font-bold px-1.5 py-0.2 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                          Admin
                        </span>
                      )}
                    </div>
                    <div className="flex items-center space-x-2 text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
                      <span className="truncate">{u.name}</span>
                      {typeof u.virtual_machines_count === "number" && (
                        <>
                          <span>•</span>
                          <span>{u.virtual_machines_count} VMs</span>
                        </>
                      )}
                    </div>
                  </div>

                  {isSelected && (
                    <Check className="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0" />
                  )}
                </button>
              );
            })}

            {/* Empty Search Result */}
            {!loading && displayedOptions.length === 0 && (
              <div className="px-3 py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                <Users className="w-6 h-6 mx-auto mb-1.5 opacity-40" />
                <p>{t("noUsersFound")}</p>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
