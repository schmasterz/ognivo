"use client";

import { type RefObject, useEffect, useRef } from "react";
import { useModalEscape } from "./useModalEscape";

export type DismissReason = "escape" | "outside" | "scroll" | "resize";

export interface UseDismissableLayerOptions {
  open: boolean;
  onDismiss: (reason: DismissReason) => void;
  triggerRef: RefObject<HTMLElement | null>;
  menuRef: RefObject<HTMLElement | null>;
}

/**
 * Dismisses a floating layer (such as a dropdown menu) on Escape, outside pointer
 * down, external scroll, or window resize while preserving internal menu scroll.
 */
export function useDismissableLayer({
  open,
  onDismiss,
  triggerRef,
  menuRef,
}: UseDismissableLayerOptions): void {
  const onDismissRef = useRef(onDismiss);

  useEffect(() => {
    onDismissRef.current = onDismiss;
  }, [onDismiss]);

  useModalEscape(() => onDismissRef.current("escape"), open);

  useEffect(() => {
    if (!open) return;

    const handleMouseDown = (event: MouseEvent) => {
      if (!(event.target instanceof Node)) return;
      if (
        menuRef.current?.contains(event.target) ||
        triggerRef.current?.contains(event.target)
      ) {
        return;
      }
      onDismissRef.current("outside");
    };

    const handleScroll = (event: Event) => {
      if (event.target instanceof Node && menuRef.current?.contains(event.target)) {
        return;
      }
      onDismissRef.current("scroll");
    };

    const handleResize = () => {
      onDismissRef.current("resize");
    };

    document.addEventListener("mousedown", handleMouseDown);
    window.addEventListener("scroll", handleScroll, {
      capture: true,
      passive: true,
    });
    window.addEventListener("resize", handleResize);

    return () => {
      document.removeEventListener("mousedown", handleMouseDown);
      window.removeEventListener("scroll", handleScroll, true);
      window.removeEventListener("resize", handleResize);
    };
  }, [open, triggerRef, menuRef]);
}
