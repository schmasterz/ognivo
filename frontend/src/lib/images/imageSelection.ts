import type {
  ImageItem,
  ImageCatalogFilter,
  ImageFilterOp,
  ImageSelectionPayload,
  ImageCatalogCounts,
} from "../api/types.ts";

export const MAX_ID_OVERRIDES = 500;
export const MAX_FILTER_OPS = 10;

export type SelectionFlow = "create" | "edit";

export type SelectionBase =
  | { kind: "all" }
  | { kind: "none" }
  | { kind: "filter"; filter: ImageCatalogFilter }
  | { kind: "current" };

export interface StagedImageSelection {
  base: SelectionBase;
  filterOps: ImageFilterOp[];
  includeIds: ReadonlySet<number>;
  excludeIds: ReadonlySet<number>;
}

export type ImageRow = Pick<
  ImageItem,
  "id" | "name" | "source" | "os_type" | "protocol" | "enabled" | "attached"
>;

/**
 * Creates the initial staged image selection for create or edit flows.
 */
export function createInitialSelection(flow: SelectionFlow): StagedImageSelection {
  return {
    base: flow === "create" ? { kind: "all" } : { kind: "current" },
    filterOps: [],
    includeIds: new Set<number>(),
    excludeIds: new Set<number>(),
  };
}

/**
 * Normalizes an ImageCatalogFilter by trimming strings and omitting empty / "all" / undefined values.
 */
export function normalizeFilter(filter?: ImageCatalogFilter | null): ImageCatalogFilter {
  if (!filter) return {};
  const normalized: ImageCatalogFilter = {};

  if (filter.search !== undefined) {
    const trimmed = filter.search.trim();
    if (trimmed.length > 0 && trimmed.toLowerCase() !== "all") {
      normalized.search = trimmed;
    }
  }

  if (filter.os_type !== undefined) {
    const trimmed = filter.os_type.trim();
    if (trimmed.length > 0 && trimmed.toLowerCase() !== "all") {
      normalized.os_type = trimmed;
    }
  }

  if (filter.protocol !== undefined) {
    const trimmed = filter.protocol.trim();
    if (trimmed.length > 0 && trimmed.toLowerCase() !== "all") {
      normalized.protocol = trimmed;
    }
  }

  if (typeof filter.enabled === "boolean") {
    normalized.enabled = filter.enabled;
  }

  return normalized;
}

/**
 * Checks whether an ImageCatalogFilter has no active filtering criteria.
 */
export function isEmptyFilter(filter?: ImageCatalogFilter | null): boolean {
  if (!filter) return true;
  const norm = normalizeFilter(filter);
  return (
    norm.search === undefined &&
    norm.os_type === undefined &&
    norm.protocol === undefined &&
    norm.enabled === undefined
  );
}

/**
 * Checks whether two ImageCatalogFilters are functionally equivalent after normalization.
 */
export function areFiltersEqual(
  a?: ImageCatalogFilter | null,
  b?: ImageCatalogFilter | null
): boolean {
  const normA = normalizeFilter(a);
  const normB = normalizeFilter(b);
  return (
    normA.search === normB.search &&
    normA.os_type === normB.os_type &&
    normA.protocol === normB.protocol &&
    normA.enabled === normB.enabled
  );
}

/**
 * Evaluates whether an image item matches the given filter criteria.
 */
export function matchesCatalogFilter(
  item: Pick<ImageItem, "name" | "source" | "os_type" | "protocol" | "enabled">,
  filter?: ImageCatalogFilter | null
): boolean {
  if (!filter) return true;
  const norm = normalizeFilter(filter);

  if (norm.search !== undefined) {
    const term = norm.search.toLowerCase();
    const nameMatch = (item.name ?? "").toLowerCase().includes(term);
    const sourceMatch = (item.source ?? "").toLowerCase().includes(term);
    if (!nameMatch && !sourceMatch) {
      return false;
    }
  }

  if (norm.os_type !== undefined && item.os_type !== norm.os_type) {
    return false;
  }

  if (norm.protocol !== undefined && item.protocol !== norm.protocol) {
    return false;
  }

  if (norm.enabled !== undefined && item.enabled !== norm.enabled) {
    return false;
  }

  return true;
}

/**
 * Evaluates selection state based purely on base + filterOps (excluding individual ID overrides).
 */
function computeBaseFilterSelection(
  base: SelectionBase,
  filterOps: readonly ImageFilterOp[],
  item: Pick<ImageItem, "name" | "source" | "os_type" | "protocol" | "enabled" | "attached">
): boolean {
  for (let i = filterOps.length - 1; i >= 0; i--) {
    if (matchesCatalogFilter(item, filterOps[i].filter)) {
      return filterOps[i].action === "attach";
    }
  }

  switch (base.kind) {
    case "all":
      return true;
    case "none":
      return false;
    case "filter":
      return matchesCatalogFilter(item, base.filter);
    case "current":
      return Boolean(item.attached);
  }
}

/**
 * Determines whether a given image item is currently selected in the staged state.
 */
export function isImageSelected(
  state: StagedImageSelection,
  item: ImageRow
): boolean {
  if (state.includeIds.has(item.id)) {
    return true;
  }
  if (state.excludeIds.has(item.id)) {
    return false;
  }
  return computeBaseFilterSelection(state.base, state.filterOps, item);
}

/**
 * Toggles an individual image item on or off in the staged selection.
 * Enforces the 500-ID manual override cap.
 */
export function toggleImage(
  state: StagedImageSelection,
  item: ImageRow
): { state: StagedImageSelection; limitReached: boolean } {
  const currentSelected = isImageSelected(state, item);
  const desiredSelected = !currentSelected;
  const baseOpSelected = computeBaseFilterSelection(state.base, state.filterOps, item);

  if (desiredSelected === baseOpSelected) {
    if (!state.includeIds.has(item.id) && !state.excludeIds.has(item.id)) {
      return { state, limitReached: false };
    }
    const newInclude = new Set(state.includeIds);
    newInclude.delete(item.id);
    const newExclude = new Set(state.excludeIds);
    newExclude.delete(item.id);
    return {
      state: {
        ...state,
        includeIds: newInclude,
        excludeIds: newExclude,
      },
      limitReached: false,
    };
  }

  if (desiredSelected) {
    if (!state.includeIds.has(item.id) && state.includeIds.size >= MAX_ID_OVERRIDES) {
      return { state, limitReached: true };
    }
    const newInclude = new Set(state.includeIds);
    newInclude.add(item.id);
    const newExclude = new Set(state.excludeIds);
    newExclude.delete(item.id);
    return {
      state: {
        ...state,
        includeIds: newInclude,
        excludeIds: newExclude,
      },
      limitReached: false,
    };
  } else {
    if (!state.excludeIds.has(item.id) && state.excludeIds.size >= MAX_ID_OVERRIDES) {
      return { state, limitReached: true };
    }
    const newInclude = new Set(state.includeIds);
    newInclude.delete(item.id);
    const newExclude = new Set(state.excludeIds);
    newExclude.add(item.id);
    return {
      state: {
        ...state,
        includeIds: newInclude,
        excludeIds: newExclude,
      },
      limitReached: false,
    };
  }
}

/**
 * Bulk selects all images matching a filter.
 * Empty filter selects the entire catalog.
 * Enforces the 10 filter operations cap.
 */
export function selectAllMatching(
  state: StagedImageSelection,
  rawFilter: ImageCatalogFilter,
  flow: SelectionFlow
): { state: StagedImageSelection; limitReached: boolean } {
  const filter = normalizeFilter(rawFilter);

  if (isEmptyFilter(filter)) {
    return {
      state: {
        base: { kind: "all" },
        filterOps: [],
        includeIds: new Set<number>(),
        excludeIds: new Set<number>(),
      },
      limitReached: false,
    };
  }

  if (
    flow === "create" &&
    state.base.kind === "none" &&
    state.filterOps.length === 0 &&
    state.includeIds.size === 0
  ) {
    return {
      state: {
        base: { kind: "filter", filter },
        filterOps: [],
        includeIds: new Set<number>(),
        excludeIds: new Set<number>(),
      },
      limitReached: false,
    };
  }

  const lastOp =
    state.filterOps.length > 0
      ? state.filterOps[state.filterOps.length - 1]
      : undefined;

  let nextOps: ImageFilterOp[];
  if (lastOp && areFiltersEqual(lastOp.filter, filter)) {
    if (lastOp.action === "attach") {
      nextOps = [...state.filterOps];
    } else {
      nextOps = [
        ...state.filterOps.slice(0, -1),
        { action: "attach", filter },
      ];
    }
  } else {
    if (state.filterOps.length >= MAX_FILTER_OPS) {
      return { state, limitReached: true };
    }
    nextOps = [...state.filterOps, { action: "attach", filter }];
  }

  return {
    state: {
      ...state,
      filterOps: nextOps,
      includeIds: new Set<number>(),
      excludeIds: new Set<number>(),
    },
    limitReached: false,
  };
}

/**
 * Bulk detaches/clears all images matching a filter.
 * Empty filter clears the entire catalog.
 * Enforces the 10 filter operations cap.
 */
export function clearAllMatching(
  state: StagedImageSelection,
  rawFilter: ImageCatalogFilter,
  flow: SelectionFlow
): { state: StagedImageSelection; limitReached: boolean } {
  const filter = normalizeFilter(rawFilter);

  if (isEmptyFilter(filter)) {
    if (flow === "create") {
      return {
        state: {
          base: { kind: "none" },
          filterOps: [],
          includeIds: new Set<number>(),
          excludeIds: new Set<number>(),
        },
        limitReached: false,
      };
    }

    return {
      state: {
        base: { kind: "current" },
        filterOps: [{ action: "detach", filter: {} }],
        includeIds: new Set<number>(),
        excludeIds: new Set<number>(),
      },
      limitReached: false,
    };
  }

  const lastOp =
    state.filterOps.length > 0
      ? state.filterOps[state.filterOps.length - 1]
      : undefined;

  let nextOps: ImageFilterOp[];
  if (lastOp && areFiltersEqual(lastOp.filter, filter)) {
    if (lastOp.action === "detach") {
      nextOps = [...state.filterOps];
    } else {
      nextOps = [
        ...state.filterOps.slice(0, -1),
        { action: "detach", filter },
      ];
    }
  } else {
    if (state.filterOps.length >= MAX_FILTER_OPS) {
      return { state, limitReached: true };
    }
    nextOps = [...state.filterOps, { action: "detach", filter }];
  }

  return {
    state: {
      ...state,
      filterOps: nextOps,
      includeIds: new Set<number>(),
      excludeIds: new Set<number>(),
    },
    limitReached: false,
  };
}

/**
 * Checks whether staged selection has changes from its initial baseline.
 */
export function hasStagedChanges(
  state: StagedImageSelection,
  flow: SelectionFlow
): boolean {
  if (flow === "create") {
    return !(
      state.base.kind === "all" &&
      state.filterOps.length === 0 &&
      state.includeIds.size === 0 &&
      state.excludeIds.size === 0
    );
  }
  return !(
    state.base.kind === "current" &&
    state.filterOps.length === 0 &&
    state.includeIds.size === 0 &&
    state.excludeIds.size === 0
  );
}

/**
 * Validates whether the current selection can be saved without violating backend validation rules.
 */
export function canSaveSelection(
  state: StagedImageSelection,
  counts: ImageCatalogCounts | null
): { valid: boolean; reason?: "empty_selection" } {
  if (
    state.base.kind === "none" &&
    state.filterOps.length === 0 &&
    state.includeIds.size === 0
  ) {
    if (counts && counts.overall.catalog > 0) {
      return { valid: false, reason: "empty_selection" };
    }
  }
  return { valid: true };
}

/**
 * Converts staged selection state to an ImageSelectionPayload for submission to the API.
 */
export function toSelectionPayload(
  state: StagedImageSelection,
  flow: SelectionFlow
): ImageSelectionPayload | undefined {
  if (flow === "create") {
    if (
      state.base.kind === "all" &&
      state.filterOps.length === 0 &&
      state.excludeIds.size === 0
    ) {
      return { mode: "all" };
    }

    if (
      state.base.kind === "all" &&
      state.filterOps.length === 0 &&
      state.excludeIds.size > 0
    ) {
      return {
        mode: "filter",
        filter: {},
        exclude_ids: Array.from(state.excludeIds),
      };
    }

    if (
      state.base.kind === "filter" &&
      state.filterOps.length === 0 &&
      state.includeIds.size === 0
    ) {
      return {
        mode: "filter",
        filter: state.base.filter,
        exclude_ids: Array.from(state.excludeIds),
      };
    }

    if (
      state.base.kind === "none" &&
      state.filterOps.length === 0 &&
      state.includeIds.size > 0
    ) {
      return {
        mode: "selected",
        ids: Array.from(state.includeIds),
      };
    }

    // Delta mode for create flow
    if (state.base.kind === "all") {
      return {
        mode: "delta",
        filter_ops: state.filterOps,
        attach_ids: Array.from(state.includeIds),
        detach_ids: Array.from(state.excludeIds),
      };
    }

    if (state.base.kind === "none") {
      return {
        mode: "delta",
        filter_ops: [{ action: "detach", filter: {} }, ...state.filterOps],
        attach_ids: Array.from(state.includeIds),
        detach_ids: Array.from(state.excludeIds),
      };
    }

    if (state.base.kind === "filter") {
      return {
        mode: "delta",
        filter_ops: [
          { action: "detach", filter: {} },
          { action: "attach", filter: state.base.filter },
          ...state.filterOps,
        ],
        attach_ids: Array.from(state.includeIds),
        detach_ids: Array.from(state.excludeIds),
      };
    }
  }

  if (flow === "edit") {
    if (!hasStagedChanges(state, "edit")) {
      return undefined;
    }

    if (
      state.base.kind === "all" &&
      state.filterOps.length === 0 &&
      state.excludeIds.size === 0
    ) {
      return { mode: "all" };
    }

    if (state.base.kind === "all") {
      return {
        mode: "delta",
        filter_ops: [{ action: "attach", filter: {} }, ...state.filterOps],
        attach_ids: Array.from(state.includeIds),
        detach_ids: Array.from(state.excludeIds),
      };
    }

    return {
      mode: "delta",
      filter_ops: state.filterOps,
      attach_ids: Array.from(state.includeIds),
      detach_ids: Array.from(state.excludeIds),
    };
  }

  return undefined;
}

/**
 * Estimates total selected image count using catalog count metrics.
 */
export function estimateSelectedCount(
  state: StagedImageSelection,
  counts: ImageCatalogCounts | null,
  filteredAdjustment?: { filter: ImageCatalogFilter }
): { selected: number; total: number; isApproximate: boolean } {
  if (!counts) {
    return { selected: 0, total: 0, isApproximate: false };
  }

  const total = counts.overall.catalog;

  // Single detach-all op (e.g. Cleared all in Edit flow)
  if (
    state.filterOps.length === 1 &&
    state.filterOps[0].action === "detach" &&
    isEmptyFilter(state.filterOps[0].filter)
  ) {
    return {
      selected: Math.max(0, Math.min(total, state.includeIds.size)),
      total,
      isApproximate: false,
    };
  }

  if (state.filterOps.length === 0) {
    if (state.base.kind === "all") {
      return {
        selected: Math.max(0, total - state.excludeIds.size),
        total,
        isApproximate: false,
      };
    }

    if (state.base.kind === "none") {
      return {
        selected: Math.max(0, Math.min(total, state.includeIds.size)),
        total,
        isApproximate: false,
      };
    }

    if (state.base.kind === "current") {
      const attached = counts.overall.attached ?? 0;
      return {
        selected: Math.max(
          0,
          Math.min(total, attached + state.includeIds.size - state.excludeIds.size)
        ),
        total,
        isApproximate: false,
      };
    }

    if (
      state.base.kind === "filter" &&
      filteredAdjustment &&
      areFiltersEqual(filteredAdjustment.filter, state.base.filter)
    ) {
      return {
        selected: Math.max(
          0,
          Math.min(
            total,
            counts.filtered.catalog - state.excludeIds.size + state.includeIds.size
          )
        ),
        total,
        isApproximate: false,
      };
    }
  }

  // Non-empty filterOps or base filter without matching adjustment: approximate
  let est: number;
  if (state.base.kind === "all") {
    est = total - state.excludeIds.size;
  } else if (state.base.kind === "none") {
    est = state.includeIds.size;
  } else if (state.base.kind === "filter") {
    est =
      filteredAdjustment && areFiltersEqual(filteredAdjustment.filter, state.base.filter)
        ? counts.filtered.catalog
        : Math.round(total / 2);
  } else {
    est = counts.overall.attached ?? 0;
  }

  for (const op of state.filterOps) {
    if (isEmptyFilter(op.filter)) {
      est = op.action === "attach" ? total : 0;
    } else {
      const matchesAdj =
        filteredAdjustment && areFiltersEqual(filteredAdjustment.filter, op.filter);
      const delta = matchesAdj
        ? (op.action === "attach" ? counts.filtered.catalog : (counts.filtered.attached ?? counts.filtered.catalog))
        : Math.min(counts.filtered.catalog, total);

      if (op.action === "attach") {
        est += delta;
      } else {
        est -= delta;
      }
    }
  }

  est += state.includeIds.size - state.excludeIds.size;

  return {
    selected: Math.max(0, Math.min(total, Math.round(est))),
    total,
    isApproximate: true,
  };
}
