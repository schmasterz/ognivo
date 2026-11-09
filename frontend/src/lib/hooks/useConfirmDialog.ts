"use client";

import React, { useState, useCallback, useRef } from "react";
import type { ConfirmVariant } from "@/components/ui/ConfirmModal";

export interface ConfirmDialogState {
  isOpen: boolean;
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: ConfirmVariant;
  isLoading?: boolean;
  onConfirm: () => void | Promise<void>;
}

export interface ConfirmActionOptions {
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  variant?: ConfirmVariant;
  onConfirm: () => void | Promise<void>;
}

export const INITIAL_CONFIRM_DIALOG_STATE: ConfirmDialogState = {
  isOpen: false,
  title: "",
  message: "",
  onConfirm: () => {},
};

/**
 * Pure execution helper for confirmation actions, facilitating deterministic unit tests
 * and consistent loading/error state transitions.
 */
export async function executeConfirmAction(params: {
  isCurrentlyLoading: () => boolean;
  setState: (updater: (prev: ConfirmDialogState) => ConfirmDialogState) => void;
  action: () => void | Promise<void>;
  onError?: (message: string) => void;
}): Promise<boolean> {
  if (params.isCurrentlyLoading()) {
    return false;
  }

  params.setState((prev) => {
    if (prev.isLoading) return prev;
    return { ...prev, isLoading: true };
  });

  try {
    await params.action();
    params.setState((prev) => ({ ...prev, isOpen: false, isLoading: false }));
    return true;
  } catch (err: unknown) {
    params.setState((prev) => ({ ...prev, isLoading: false }));
    if (err instanceof Error) {
      params.onError?.(err.message);
    }
    return false;
  }
}

export interface UseConfirmDialogResult {
  confirmDialog: ConfirmDialogState;
  setConfirmDialog: React.Dispatch<React.SetStateAction<ConfirmDialogState>>;
  requestConfirm: (options: ConfirmActionOptions) => void;
  closeConfirmDialog: () => void;
}

export function useConfirmDialog(
  onError?: (message: string) => void
): UseConfirmDialogResult {
  const [confirmDialog, setConfirmDialog] = useState<ConfirmDialogState>(
    INITIAL_CONFIRM_DIALOG_STATE
  );
  const loadingRef = useRef(false);
  const onErrorRef = useRef(onError);

  React.useEffect(() => {
    onErrorRef.current = onError;
  }, [onError]);

  const closeConfirmDialog = useCallback(() => {
    loadingRef.current = false;
    setConfirmDialog((prev) => ({ ...prev, isOpen: false, isLoading: false }));
  }, []);

  const requestConfirm = useCallback((options: ConfirmActionOptions) => {
    loadingRef.current = false;
    setConfirmDialog({
      isOpen: true,
      title: options.title,
      message: options.message,
      confirmText: options.confirmText,
      cancelText: options.cancelText,
      variant: options.variant,
      isLoading: false,
      onConfirm: async () => {
        await executeConfirmAction({
          isCurrentlyLoading: () => loadingRef.current,
          setState: (updater) => {
            setConfirmDialog((prev) => {
              const next = updater(prev);
              loadingRef.current = Boolean(next.isLoading);
              return next;
            });
          },
          action: options.onConfirm,
          onError: (msg) => onErrorRef.current?.(msg),
        });
      },
    });
  }, []);

  return {
    confirmDialog,
    setConfirmDialog,
    requestConfirm,
    closeConfirmDialog,
  };
}
