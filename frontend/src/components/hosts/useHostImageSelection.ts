"use client";

import { useState, useEffect, useCallback, type Dispatch, type SetStateAction } from "react";
import { api, ImageCatalogCounts } from "@/lib/api";
import {
  createInitialSelection,
  canSaveSelection,
  StagedImageSelection,
} from "@/lib/images/imageSelection";

export interface UseHostImageSelectionOptions {
  mode: "create" | "edit";
  hostId?: number;
  initialAutoAttach?: boolean;
}

export interface UseHostImageSelectionResult {
  autoAttachImages: boolean;
  setAutoAttachImages: Dispatch<SetStateAction<boolean>>;
  imageSelection: StagedImageSelection;
  setImageSelection: Dispatch<SetStateAction<StagedImageSelection>>;
  imageCounts: ImageCatalogCounts | null;
  setImageCounts: Dispatch<SetStateAction<ImageCatalogCounts | null>>;
  countsLoading: boolean;
  selectionValidation: ReturnType<typeof canSaveSelection>;
  resetImageSelection: (nextAutoAttach?: boolean) => void;
}

/**
 * Manages host image selection state, catalog count loading, and selection validation
 * for host create and edit modals.
 */
export function useHostImageSelection({
  mode,
  hostId,
  initialAutoAttach = true,
}: UseHostImageSelectionOptions): UseHostImageSelectionResult {
  const [autoAttachImages, setAutoAttachImages] = useState(initialAutoAttach);
  const [imageSelection, setImageSelection] = useState<StagedImageSelection>(() =>
    createInitialSelection(mode)
  );
  const [imageCounts, setImageCounts] = useState<ImageCatalogCounts | null>(null);
  const [countsLoading, setCountsLoading] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      setCountsLoading(true);
    });

    api
      .adminGetImages(
        mode === "edit" && hostId !== undefined
          ? { host_id: hostId, per_page: 1 }
          : { per_page: 1 },
        { signal: controller.signal }
      )
      .then((res) => {
        setImageCounts(res.counts);
      })
      .catch((err: unknown) => {
        if (err instanceof DOMException && err.name === "AbortError") {
          return;
        }
      })
      .finally(() => {
        setCountsLoading(false);
      });

    return () => {
      controller.abort();
    };
  }, [mode, hostId]);

  const resetImageSelection = useCallback(
    (nextAutoAttach?: boolean) => {
      setAutoAttachImages(nextAutoAttach ?? initialAutoAttach);
      setImageSelection(createInitialSelection(mode));
    },
    [initialAutoAttach, mode]
  );

  const selectionValidation = canSaveSelection(imageSelection, imageCounts);

  return {
    autoAttachImages,
    setAutoAttachImages,
    imageSelection,
    setImageSelection,
    imageCounts,
    setImageCounts,
    countsLoading,
    selectionValidation,
    resetImageSelection,
  };
}
