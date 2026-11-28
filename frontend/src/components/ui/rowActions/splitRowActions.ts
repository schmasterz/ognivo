export type RowActionSeverity = "safe" | "caution" | "disruptive" | "critical";

export interface SplittableAction {
  id: string;
  severity: RowActionSeverity;
  hidden?: boolean;
}

export interface SplitResult<T> {
  inline: T[];
  menu: T[];
  dividerBeforeId: string | null;
}

const SEVERITY_ORDER: Record<RowActionSeverity, number> = {
  safe: 0,
  caution: 1,
  disruptive: 2,
  critical: 3,
};

function clampMaxVisible(maxVisible: number): number {
  if (!Number.isFinite(maxVisible) || maxVisible < 0) {
    return 0;
  }
  return Math.floor(maxVisible);
}

/**
 * Splits row actions into inline icon buttons and an overflow dropdown menu.
 * Only "safe" and "caution" actions may appear inline; "disruptive" and "critical"
 * actions are always placed in the overflow menu.
 */
export function splitRowActions<T extends SplittableAction>(
  actions: readonly T[],
  maxVisible = 2,
): SplitResult<T> {
  const limit = clampMaxVisible(maxVisible);

  const visibleEntries = actions
    .filter((action) => action.hidden !== true)
    .map((action, index) => ({ action, index }));

  const inlineCandidates = visibleEntries
    .filter(
      ({ action }) =>
        action.severity === "safe" || action.severity === "caution",
    )
    .sort(
      (a, b) =>
        SEVERITY_ORDER[a.action.severity] - SEVERITY_ORDER[b.action.severity] ||
        a.index - b.index,
    );

  const chosenInline = inlineCandidates.slice(0, limit);
  const inlineIndices = new Set(chosenInline.map(({ index }) => index));

  const inline = chosenInline.map(({ action }) => action);

  const menu = visibleEntries
    .filter(({ index }) => !inlineIndices.has(index))
    .sort(
      (a, b) =>
        SEVERITY_ORDER[a.action.severity] - SEVERITY_ORDER[b.action.severity] ||
        a.index - b.index,
    )
    .map(({ action }) => action);

  const firstCriticalIndex = menu.findIndex(
    (action) => action.severity === "critical",
  );
  const dividerBeforeId =
    firstCriticalIndex > 0 ? menu[firstCriticalIndex].id : null;

  return { inline, menu, dividerBeforeId };
}
