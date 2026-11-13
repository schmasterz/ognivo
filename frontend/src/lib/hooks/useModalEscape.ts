"use client";

import { useEffect, useRef } from "react";

// Global stack of active modal escape handlers
const escapeStack: (() => void)[] = [];

/**
 * Dispatches escape key press to the topmost active modal handler.
 * Returns true if an active modal handler was invoked, false otherwise.
 */
export function dispatchModalEscape(): boolean {
  if (escapeStack.length > 0) {
    const topHandler = escapeStack[escapeStack.length - 1];
    topHandler();
    return true;
  }
  return false;
}

/**
 * Registers a modal escape handler onto the global stack.
 * Returns an unregister function.
 */
export function registerModalEscape(handler: () => void): () => void {
  escapeStack.push(handler);
  return () => {
    const idx = escapeStack.lastIndexOf(handler);
    if (idx !== -1) {
      escapeStack.splice(idx, 1);
    }
  };
}

/**
 * Returns current depth of the modal escape stack.
 */
export function getModalEscapeStackDepth(): number {
  return escapeStack.length;
}

if (typeof window !== "undefined") {
  window.addEventListener("keydown", (e: KeyboardEvent) => {
    if (e.defaultPrevented) return;

    if (e.key === "Escape") {
      if (dispatchModalEscape()) {
        e.preventDefault();
      }
    }
  });
}

/**
 * Registers an Escape key handler for a modal or drawer.
 * Utilizes a stack so that when multiple nested modals or overlays are open,
 * pressing Esc closes only the topmost active modal.
 *
 * @param onClose Callback to invoke when Escape is pressed
 * @param enabled Whether the handler is currently active (defaults to true)
 */
export function useModalEscape(onClose: () => void, enabled: boolean = true) {
  const onCloseRef = useRef(onClose);

  useEffect(() => {
    onCloseRef.current = onClose;
  }, [onClose]);

  useEffect(() => {
    if (!enabled) return;

    const handler = () => {
      onCloseRef.current();
    };

    return registerModalEscape(handler);
  }, [enabled]);
}
