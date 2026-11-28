import { type RowActionSeverity, type RowActionTone } from "./types";

export const INLINE_BUTTON_BASE =
  "p-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 transition inline-flex items-center justify-center cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40";

export const INLINE_BUTTON_DISABLED =
  "p-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 transition inline-flex items-center justify-center opacity-40 cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40";

export const INLINE_TONE_HOVER: Record<RowActionTone, string> = {
  neutral:
    "hover:bg-gray-200 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-white",
  info: "hover:bg-blue-500/20 dark:hover:bg-blue-500/20 hover:text-blue-600 dark:hover:text-blue-400",
  accent:
    "hover:bg-indigo-500/20 dark:hover:bg-indigo-500/20 hover:text-indigo-600 dark:hover:text-indigo-400",
  success:
    "hover:bg-emerald-500/20 dark:hover:bg-emerald-500/20 hover:text-emerald-600 dark:hover:text-emerald-400",
};

export const TRIGGER_EXPANDED =
  "bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white";

export const MENU_CONTAINER =
  "fixed z-[90] w-52 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 py-1 shadow-lg focus:outline-none";

export const MENU_DIVIDER = "my-1 h-px bg-gray-200 dark:bg-gray-800";

export const MENU_ITEM_BASE =
  "h-9 px-3 flex w-full items-center gap-2.5 text-xs font-medium text-left transition focus:outline-none";

export interface MenuItemSeverityStyle {
  item: string;
  icon: string;
}

export const MENU_ITEM_ENABLED_STYLES: Record<
  RowActionSeverity,
  MenuItemSeverityStyle
> = {
  safe: {
    item: "cursor-pointer text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 focus:bg-gray-100 dark:focus:bg-gray-800",
    icon: "w-4 h-4 shrink-0 text-gray-500 dark:text-gray-400",
  },
  caution: {
    item: "cursor-pointer text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 focus:bg-gray-100 dark:focus:bg-gray-800",
    icon: "w-4 h-4 shrink-0 text-gray-500 dark:text-gray-400",
  },
  disruptive: {
    item: "cursor-pointer text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 focus:bg-gray-100 dark:focus:bg-gray-800",
    icon: "w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400",
  },
  critical: {
    item: "cursor-pointer text-red-600 dark:text-red-400 hover:bg-red-500/10 dark:hover:bg-red-500/15 focus:bg-red-500/10 dark:focus:bg-red-500/15",
    icon: "w-4 h-4 shrink-0 text-red-600 dark:text-red-400",
  },
};

export const MENU_ITEM_DISABLED_STYLES: Record<
  RowActionSeverity,
  MenuItemSeverityStyle
> = {
  safe: {
    item: "opacity-40 cursor-not-allowed text-gray-700 dark:text-gray-200 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-gray-400/50",
    icon: "w-4 h-4 shrink-0 text-gray-500 dark:text-gray-400",
  },
  caution: {
    item: "opacity-40 cursor-not-allowed text-gray-700 dark:text-gray-200 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-gray-400/50",
    icon: "w-4 h-4 shrink-0 text-gray-500 dark:text-gray-400",
  },
  disruptive: {
    item: "opacity-40 cursor-not-allowed text-gray-700 dark:text-gray-200 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-gray-400/50",
    icon: "w-4 h-4 shrink-0 text-amber-600 dark:text-amber-400",
  },
  critical: {
    item: "opacity-40 cursor-not-allowed text-red-600 dark:text-red-400 focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-gray-400/50",
    icon: "w-4 h-4 shrink-0 text-red-600 dark:text-red-400",
  },
};
