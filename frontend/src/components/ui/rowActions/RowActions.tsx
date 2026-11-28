"use client";

import { useCallback, useId, useRef, useState } from "react";
import { EllipsisVertical, Loader2 } from "lucide-react";
import { useI18n } from "@/lib/i18n/I18nContext";
import { cn } from "@/lib/utils";
import { ActionMenu } from "./ActionMenu";
import { computeMenuPlacement, type Placement } from "./menuPlacement";
import {
  INLINE_BUTTON_BASE,
  INLINE_BUTTON_DISABLED,
  INLINE_TONE_HOVER,
  TRIGGER_EXPANDED,
} from "./rowActionStyles";
import { splitRowActions } from "./splitRowActions";
import { type RowAction } from "./types";

export interface RowActionsProps {
  actions: RowAction[];
  maxVisible?: number;
  menuLabel?: string;
  className?: string;
}

interface MenuOpenState {
  open: boolean;
  placement: Placement | null;
  initialFocus: "first" | "none";
}

const CLOSED_MENU_STATE: MenuOpenState = {
  open: false,
  placement: null,
  initialFocus: "none",
};

/**
 * Renders unified table row actions: up to `maxVisible` inline safe/caution icon
 * buttons and an overflow portal dropdown menu for remaining and high-severity actions.
 */
export function RowActions({
  actions,
  maxVisible = 2,
  menuLabel,
  className,
}: RowActionsProps) {
  const { t } = useI18n();
  const menuId = useId();
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const [menuState, setMenuState] = useState<MenuOpenState>(CLOSED_MENU_STATE);

  const handleClose = useCallback(() => {
    setMenuState(CLOSED_MENU_STATE);
  }, []);

  const { inline, menu, dividerBeforeId } = splitRowActions(
    actions,
    maxVisible,
  );

  if (inline.length === 0 && menu.length === 0) {
    return null;
  }

  const isOpen =
    menuState.open && menu.length > 0 && menuState.placement !== null;
  const resolvedMenuLabel = menuLabel ?? t("rowActionsMore");
  const hasLoadingMenuAction = menu.some((action) => action.loading === true);

  const openMenu = (
    triggerElement: HTMLElement,
    initialFocus: "first" | "none",
  ) => {
    const rect = triggerElement.getBoundingClientRect();
    const placement = computeMenuPlacement({
      triggerRect: {
        top: rect.top,
        bottom: rect.bottom,
        right: rect.right,
      },
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,
      itemCount: menu.length,
      hasDivider: dividerBeforeId !== null,
    });
    setMenuState({ open: true, placement, initialFocus });
  };

  return (
    <div className={cn("flex items-center justify-end gap-1.5", className)}>
      {inline.map((action) => {
        const Icon = action.icon;
        const isActionDisabled = Boolean(action.disabled);

        return (
          <button
            key={action.id}
            type="button"
            aria-label={action.label}
            aria-disabled={isActionDisabled ? "true" : undefined}
            title={
              action.disabled && action.disabledReason
                ? action.disabledReason
                : action.label
            }
            className={
              isActionDisabled
                ? INLINE_BUTTON_DISABLED
                : cn(
                    INLINE_BUTTON_BASE,
                    INLINE_TONE_HOVER[action.tone ?? "neutral"],
                  )
            }
            onClick={(event) => {
              event.stopPropagation();
              if (action.disabled || action.loading) {
                event.preventDefault();
                return;
              }
              action.onSelect();
            }}
          >
            {action.loading ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Icon className="w-4 h-4" />
            )}
          </button>
        );
      })}

      {menu.length > 0 && (
        <button
          ref={triggerRef}
          type="button"
          aria-haspopup="menu"
          aria-expanded={isOpen}
          aria-controls={isOpen ? menuId : undefined}
          aria-label={resolvedMenuLabel}
          title={resolvedMenuLabel}
          className={cn(
            INLINE_BUTTON_BASE,
            INLINE_TONE_HOVER.neutral,
            isOpen && TRIGGER_EXPANDED,
          )}
          onClick={(event) => {
            event.stopPropagation();
            if (isOpen) {
              handleClose();
              return;
            }
            openMenu(event.currentTarget, event.detail === 0 ? "first" : "none");
          }}
          onKeyDown={(event) => {
            if (!isOpen && event.key === "ArrowDown") {
              event.preventDefault();
              event.stopPropagation();
              openMenu(event.currentTarget, "first");
              return;
            }
            if (
              isOpen &&
              (event.key === "ArrowDown" || event.key === "ArrowUp")
            ) {
              event.preventDefault();
              event.stopPropagation();
              document
                .getElementById(menuId)
                ?.querySelector<HTMLButtonElement>('[role="menuitem"]')
                ?.focus();
            }
          }}
        >
          {hasLoadingMenuAction ? (
            <Loader2 className="w-4 h-4 animate-spin" />
          ) : (
            <EllipsisVertical className="w-4 h-4" />
          )}
        </button>
      )}

      {isOpen && menuState.placement !== null && (
        <ActionMenu
          items={menu}
          dividerBeforeId={dividerBeforeId}
          placement={menuState.placement}
          menuId={menuId}
          ariaLabel={resolvedMenuLabel}
          initialFocus={menuState.initialFocus}
          onClose={handleClose}
          triggerRef={triggerRef}
        />
      )}
    </div>
  );
}
