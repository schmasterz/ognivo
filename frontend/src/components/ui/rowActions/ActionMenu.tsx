"use client";

import {
  Fragment,
  type KeyboardEvent,
  type RefObject,
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import { Loader2 } from "lucide-react";
import {
  type DismissReason,
  useDismissableLayer,
} from "@/lib/hooks/useDismissableLayer";
import { cn } from "@/lib/utils";
import { type Placement } from "./menuPlacement";
import {
  MENU_CONTAINER,
  MENU_DIVIDER,
  MENU_ITEM_BASE,
  MENU_ITEM_DISABLED_STYLES,
  MENU_ITEM_ENABLED_STYLES,
} from "./rowActionStyles";
import { type RowAction } from "./types";

export type MenuCloseReason = DismissReason | "select" | "tab";

export interface ActionMenuProps {
  items: readonly RowAction[];
  dividerBeforeId: string | null;
  placement: Placement;
  menuId: string;
  ariaLabel: string;
  initialFocus: "first" | "none";
  onClose: (reason: MenuCloseReason) => void;
  triggerRef: RefObject<HTMLElement | null>;
}

/**
 * Portal-rendered overflow menu for row actions with roving tabindex,
 * keyboard navigation, and dismissable-layer integration.
 */
export function ActionMenu({
  items,
  dividerBeforeId,
  placement,
  menuId,
  ariaLabel,
  initialFocus,
  onClose,
  triggerRef,
}: ActionMenuProps) {
  const [activeIndex, setActiveIndex] = useState(0);
  const safeActiveIndex =
    items.length > 0 ? Math.min(activeIndex, items.length - 1) : 0;

  const menuRef = useRef<HTMLDivElement | null>(null);
  const itemRefs = useRef<Array<HTMLButtonElement | null>>([]);

  const handleDismiss = useCallback(
    (reason: DismissReason) => {
      onClose(reason);
      if (reason === "escape") {
        triggerRef.current?.focus();
      }
    },
    [onClose, triggerRef],
  );

  useDismissableLayer({
    open: true,
    onDismiss: handleDismiss,
    triggerRef,
    menuRef,
  });

  useEffect(() => {
    if (initialFocus === "first") {
      itemRefs.current[0]?.focus();
    }
  }, [initialFocus]);

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === "Tab") {
      event.preventDefault();
      event.stopPropagation();
      onClose("tab");
      triggerRef.current?.focus();
      return;
    }

    if (items.length === 0) return;

    if (event.key === "ArrowDown") {
      event.preventDefault();
      event.stopPropagation();
      const next = (safeActiveIndex + 1) % items.length;
      setActiveIndex(next);
      itemRefs.current[next]?.focus();
    } else if (event.key === "ArrowUp") {
      event.preventDefault();
      event.stopPropagation();
      const prev = (safeActiveIndex - 1 + items.length) % items.length;
      setActiveIndex(prev);
      itemRefs.current[prev]?.focus();
    } else if (event.key === "Home") {
      event.preventDefault();
      event.stopPropagation();
      setActiveIndex(0);
      itemRefs.current[0]?.focus();
    } else if (event.key === "End") {
      event.preventDefault();
      event.stopPropagation();
      const last = items.length - 1;
      setActiveIndex(last);
      itemRefs.current[last]?.focus();
    }
  };

  if (typeof document === "undefined") {
    return null;
  }

  return createPortal(
    <div
      ref={menuRef}
      id={menuId}
      role="menu"
      aria-label={ariaLabel}
      tabIndex={-1}
      className={MENU_CONTAINER}
      style={{
        top: placement.side === "bottom" ? placement.top : undefined,
        bottom: placement.side === "top" ? placement.bottom : undefined,
        right: placement.right,
        maxHeight: placement.maxHeight,
      }}
      onKeyDown={handleKeyDown}
    >
      {items.map((item, index) => {
        const isItemDisabled = Boolean(item.disabled);
        const styles = isItemDisabled
          ? MENU_ITEM_DISABLED_STYLES[item.severity]
          : MENU_ITEM_ENABLED_STYLES[item.severity];
        const Icon = item.icon;

        return (
          <Fragment key={item.id}>
            {dividerBeforeId === item.id && (
              <div role="separator" className={MENU_DIVIDER} />
            )}
            <button
              ref={(el) => {
                itemRefs.current[index] = el;
              }}
              type="button"
              role="menuitem"
              tabIndex={index === safeActiveIndex ? 0 : -1}
              aria-disabled={isItemDisabled ? "true" : undefined}
              title={
                item.disabled && item.disabledReason
                  ? item.disabledReason
                  : undefined
              }
              className={cn(MENU_ITEM_BASE, styles.item)}
              onFocus={() => setActiveIndex(index)}
              onClick={(event) => {
                event.stopPropagation();
                if (item.disabled || item.loading) {
                  event.preventDefault();
                  return;
                }
                onClose("select");
                triggerRef.current?.focus();
                item.onSelect();
              }}
            >
              {item.loading ? (
                <Loader2 className={cn(styles.icon, "animate-spin")} />
              ) : (
                <Icon className={styles.icon} />
              )}
              <span className="truncate">{item.label}</span>
            </button>
          </Fragment>
        );
      })}
    </div>,
    document.body,
  );
}
