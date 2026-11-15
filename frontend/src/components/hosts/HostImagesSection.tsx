"use client";

import React, { useState } from "react";
import { Layers, Loader2, AlertCircle } from "lucide-react";
import type { ImageCatalogCounts } from "@/lib/api";
import {
  SelectionFlow,
  StagedImageSelection,
  estimateSelectedCount,
} from "@/lib/images/imageSelection";
import { ImageSelectionDrawer } from "./ImageSelectionDrawer";
import { useI18n } from "@/lib/i18n/I18nContext";

export interface HostImagesSectionProps {
  flow: SelectionFlow;
  hostId?: number | null;
  autoAttachImages: boolean;
  onAutoAttachChange: (checked: boolean) => void;
  selection: StagedImageSelection;
  onSelectionChange: (next: StagedImageSelection) => void;
  counts: ImageCatalogCounts | null;
  onCountsUpdate: (counts: ImageCatalogCounts) => void;
  countsLoading?: boolean;
  validationError?: string | null;
}

export function HostImagesSection({
  flow,
  hostId,
  autoAttachImages,
  onAutoAttachChange,
  selection,
  onSelectionChange,
  counts,
  onCountsUpdate,
  countsLoading = false,
  validationError,
}: HostImagesSectionProps) {
  const { t } = useI18n();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const renderSummary = () => {
    if (countsLoading && !counts) {
      return (
        <span className="inline-flex items-center space-x-1.5 text-gray-500 dark:text-gray-400">
          <Loader2 className="w-3 h-3 animate-spin" />
          <span>{t("loadingImages")}</span>
        </span>
      );
    }

    if (counts) {
      const est = estimateSelectedCount(selection, counts);
      const template = est.isApproximate
        ? t("imagesSelectedApproxSummary")
        : t("imagesSelectedSummary");
      const summaryText = template
        .replace("{selected}", est.selected.toLocaleString())
        .replace("{total}", est.total.toLocaleString());
      return <span>{summaryText}</span>;
    }

    return <span>{t("allImagesSelectedDefault")}</span>;
  };

  return (
    <div className="p-4 rounded-2xl bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700/60 space-y-3">
      {/* Header Row */}
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center space-x-2.5 min-w-0">
          <div className="p-2 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 shrink-0">
            <Layers className="w-4 h-4" />
          </div>
          <div className="min-w-0">
            <h3 className="text-xs font-bold uppercase tracking-wider text-gray-700 dark:text-gray-300">
              {t("nodeImagesTitle")}
            </h3>
            <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
              {renderSummary()}
            </div>
          </div>
        </div>

        <button
          type="button"
          onClick={() => setDrawerOpen(true)}
          className="px-3 py-1.5 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-white dark:hover:bg-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-200 transition shadow-sm shrink-0"
        >
          {t("manageImagesBtn")}
        </button>
      </div>

      {/* Checkbox Row */}
      <div className="pt-2 border-t border-gray-200/60 dark:border-gray-700/60">
        <label className="flex items-start space-x-2.5 cursor-pointer">
          <input
            type="checkbox"
            checked={autoAttachImages}
            onChange={(e) => onAutoAttachChange(e.target.checked)}
            className="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
          />
          <div className="text-xs">
            <span className="font-medium text-gray-800 dark:text-gray-200">
              {t("autoAttachNewImages")}
            </span>
            <p className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">
              {t("autoAttachNewImagesDesc")}
            </p>
          </div>
        </label>
      </div>

      {/* Inline Validation Notice */}
      {validationError && (
        <div className="p-2.5 rounded-xl bg-red-500/10 border border-red-500/20 text-xs text-red-600 dark:text-red-400 flex items-center space-x-2">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{validationError}</span>
        </div>
      )}

      {/* Image Selection Drawer */}
      <ImageSelectionDrawer
        isOpen={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        flow={flow}
        hostId={hostId}
        selection={selection}
        onChange={onSelectionChange}
        onCountsUpdate={onCountsUpdate}
      />
    </div>
  );
}
