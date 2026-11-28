export const MENU_ITEM_HEIGHT = 36; // h-9
export const MENU_VERTICAL_PADDING = 8; // py-1
export const MENU_DIVIDER_HEIGHT = 9; // 1px line + my-1
export const VIEWPORT_PADDING = 8;
export const TRIGGER_GAP = 4;
export const MENU_MIN_MAX_HEIGHT = 96;

export interface PlacementInput {
  triggerRect: { top: number; bottom: number; right: number };
  viewportWidth: number;
  viewportHeight: number;
  itemCount: number;
  hasDivider: boolean;
}

export interface Placement {
  side: "bottom" | "top";
  top?: number; // set when side === "bottom"
  bottom?: number; // set when side === "top" (distance from viewport bottom)
  right: number; // distance from viewport right edge, clamped to >= VIEWPORT_PADDING
  maxHeight: number; // menu gets overflow-y-auto with this max-height
}

/**
 * Computes fixed-position coordinates and max-height for a row action menu
 * relative to its trigger element and the current viewport bounds.
 */
export function computeMenuPlacement(input: PlacementInput): Placement {
  const { triggerRect, viewportWidth, viewportHeight, itemCount, hasDivider } =
    input;

  const estimatedHeight =
    itemCount * MENU_ITEM_HEIGHT +
    MENU_VERTICAL_PADDING +
    (hasDivider ? MENU_DIVIDER_HEIGHT : 0);

  const spaceBelow =
    viewportHeight - triggerRect.bottom - TRIGGER_GAP - VIEWPORT_PADDING;
  const spaceAbove = triggerRect.top - TRIGGER_GAP - VIEWPORT_PADDING;

  const side: "bottom" | "top" =
    spaceBelow >= estimatedHeight
      ? "bottom"
      : spaceAbove > spaceBelow
        ? "top"
        : "bottom";

  const right = Math.max(VIEWPORT_PADDING, viewportWidth - triggerRect.right);

  if (side === "bottom") {
    return {
      side: "bottom",
      top: triggerRect.bottom + TRIGGER_GAP,
      right,
      maxHeight: Math.max(spaceBelow, MENU_MIN_MAX_HEIGHT),
    };
  }

  return {
    side: "top",
    bottom: viewportHeight - triggerRect.top + TRIGGER_GAP,
    right,
    maxHeight: Math.max(spaceAbove, MENU_MIN_MAX_HEIGHT),
  };
}
