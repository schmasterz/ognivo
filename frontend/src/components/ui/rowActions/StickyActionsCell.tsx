import { type TdHTMLAttributes, type ThHTMLAttributes } from "react";
import { cn } from "@/lib/utils";

export type StickyActionsSurface = "gray" | "zinc";

const TH_SURFACE_CLASSES: Record<StickyActionsSurface, string> = {
  gray: "sticky right-0 z-20 px-3 sm:px-4 py-3.5 text-right font-semibold bg-gray-50 dark:bg-[color-mix(in_oklab,var(--color-gray-950)_60%,var(--background))] border-l border-gray-200 dark:border-gray-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]",
  zinc: "sticky right-0 z-20 px-3 sm:px-4 py-3.5 text-right font-semibold bg-zinc-50 dark:bg-[color-mix(in_oklab,var(--color-zinc-800)_60%,var(--color-zinc-900))] border-l border-zinc-200 dark:border-zinc-800 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)]",
};

const TD_SURFACE_CLASSES: Record<StickyActionsSurface, string> = {
  gray: "sticky right-0 z-10 px-3 sm:px-4 py-3.5 text-right bg-white dark:bg-[color-mix(in_oklab,var(--color-gray-900)_60%,var(--background))] group-hover:bg-gray-50 dark:group-hover:bg-[color-mix(in_oklab,var(--color-gray-800)_40%,color-mix(in_oklab,var(--color-gray-900)_60%,var(--background)))] border-l border-gray-200/80 dark:border-gray-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors",
  zinc: "sticky right-0 z-10 px-3 sm:px-4 py-3.5 text-right bg-white dark:bg-zinc-900 group-hover:bg-zinc-50 dark:group-hover:bg-[color-mix(in_oklab,var(--color-zinc-800)_40%,var(--color-zinc-900))] border-l border-zinc-200/80 dark:border-zinc-800/80 shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.05)] dark:shadow-[-4px_0_6px_-2px_rgba(0,0,0,0.3)] transition-colors",
};

export interface StickyActionsThProps
  extends ThHTMLAttributes<HTMLTableCellElement> {
  surface?: StickyActionsSurface;
}

export interface StickyActionsTdProps
  extends TdHTMLAttributes<HTMLTableCellElement> {
  surface?: StickyActionsSurface;
}

/**
 * Sticky right-pinned actions header cell with opaque surface-matched backgrounds.
 */
export function StickyActionsTh({
  surface = "gray",
  className,
  scope = "col",
  children,
  ...props
}: StickyActionsThProps) {
  return (
    <th
      scope={scope}
      className={cn(TH_SURFACE_CLASSES[surface], className)}
      {...props}
    >
      {children}
    </th>
  );
}

/**
 * Sticky right-pinned actions body cell with opaque surface-matched backgrounds.
 */
export function StickyActionsTd({
  surface = "gray",
  className,
  children,
  ...props
}: StickyActionsTdProps) {
  return (
    <td className={cn(TD_SURFACE_CLASSES[surface], className)} {...props}>
      {children}
    </td>
  );
}
