"use client";

import React from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";

interface PaginationBarProps {
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
  onPageChange: (page: number) => void;
  onPerPageChange?: (perPage: number) => void;
}

export function PaginationBar({
  currentPage,
  lastPage,
  perPage,
  total,
  onPageChange,
  onPerPageChange,
}: PaginationBarProps) {
  const { t } = useI18n();

  if (total <= 0) {
    return null;
  }

  const from = (currentPage - 1) * perPage + 1;
  const to = Math.min(currentPage * perPage, total);

  // Build visible page numbers with ellipsis if needed
  const getPageNumbers = (): (number | "...")[] => {
    const pages: (number | "...")[] = [];
    if (lastPage <= 7) {
      for (let i = 1; i <= lastPage; i++) {
        pages.push(i);
      }
      return pages;
    }

    pages.push(1);
    if (currentPage > 3) {
      pages.push("...");
    }

    const start = Math.max(2, currentPage - 1);
    const end = Math.min(lastPage - 1, currentPage + 1);
    for (let i = start; i <= end; i++) {
      pages.push(i);
    }

    if (currentPage < lastPage - 2) {
      pages.push("...");
    }
    pages.push(lastPage);

    return pages;
  };

  return (
    <div className="px-6 py-3.5 bg-gray-50/80 dark:bg-gray-950/40 border-t border-gray-200 dark:border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-600 dark:text-gray-400">
      {/* Left: Showing X to Y of Z & Per-Page selector */}
      <div className="flex flex-wrap items-center gap-4">
        <div>
          {t("showingResults")}{" "}
          <span className="font-semibold text-gray-900 dark:text-white">{from}</span>{" "}
          {t("toResults")}{" "}
          <span className="font-semibold text-gray-900 dark:text-white">{to}</span>{" "}
          {t("ofResults")}{" "}
          <span className="font-semibold text-gray-900 dark:text-white">{total}</span>{" "}
          {t("resultsLabel")}
        </div>

        {onPerPageChange && (
          <div className="flex items-center space-x-2">
            <span className="text-gray-500 dark:text-gray-400">{t("perPageLabel")}:</span>
            <select
              value={perPage}
              onChange={(e) => onPerPageChange(parseInt(e.target.value, 10))}
              className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg px-2 py-1 text-xs font-medium text-gray-800 dark:text-gray-200 focus:outline-none focus:border-blue-500 cursor-pointer"
            >
              <option value={5}>5</option>
              <option value={10}>10</option>
              <option value={25}>25</option>
              <option value={50}>50</option>
            </select>
          </div>
        )}
      </div>

      {/* Right: Page buttons */}
      <div className="flex items-center space-x-1">
        <button
          type="button"
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage <= 1}
          title={t("prevPage")}
          className="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40 disabled:pointer-events-none transition font-medium"
        >
          <ChevronLeft className="w-3.5 h-3.5 mr-1" />
          <span>{t("prevPage")}</span>
        </button>

        <div className="flex items-center space-x-1">
          {getPageNumbers().map((p, idx) =>
            p === "..." ? (
              <span key={`ellipsis-${idx}`} className="px-2 py-1 text-gray-400">
                ...
              </span>
            ) : (
              <button
                key={p}
                type="button"
                onClick={() => onPageChange(p)}
                className={`min-w-[30px] h-7 px-2 rounded-lg text-xs font-semibold transition ${
                  p === currentPage
                    ? "bg-blue-600 text-white shadow-sm shadow-blue-500/20"
                    : "bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800"
                }`}
              >
                {p}
              </button>
            )
          )}
        </div>

        <button
          type="button"
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage >= lastPage}
          title={t("nextPage")}
          className="inline-flex items-center px-2.5 py-1.5 rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 disabled:opacity-40 disabled:pointer-events-none transition font-medium"
        >
          <span>{t("nextPage")}</span>
          <ChevronRight className="w-3.5 h-3.5 ml-1" />
        </button>
      </div>
    </div>
  );
}
