import { describe, it } from "node:test";
import assert from "node:assert/strict";
import type { ImageCatalogCounts, ImageCatalogFilter } from "../../../src/lib/api/types.ts";
import {
  createInitialSelection,
  normalizeFilter,
  isEmptyFilter,
  areFiltersEqual,
  matchesCatalogFilter,
  isImageSelected,
  toggleImage,
  selectAllMatching,
  clearAllMatching,
  hasStagedChanges,
  canSaveSelection,
  toSelectionPayload,
  estimateSelectedCount,
  MAX_ID_OVERRIDES,
  MAX_FILTER_OPS,
} from "../../../src/lib/images/imageSelection.ts";
import type { ImageRow } from "../../../src/lib/images/imageSelection.ts";

const sampleLinux: ImageRow = {
  id: 1,
  name: "ubuntu-24.04-lts",
  source: "images:ubuntu/24.04",
  protocol: "simplestreams",
  os_type: "linux",
  enabled: true,
  attached: true,
};

const sampleWindows: ImageRow = {
  id: 2,
  name: "windows-server-2022",
  source: "custom:win2022",
  protocol: "direct",
  os_type: "windows",
  enabled: true,
  attached: false,
};

const sampleBsdDisabled: ImageRow = {
  id: 3,
  name: "freebsd-14",
  source: "images:freebsd/14",
  protocol: "simplestreams",
  os_type: "bsd",
  enabled: false,
  attached: false,
};

const sampleCounts: ImageCatalogCounts = {
  overall: { catalog: 100, attached: 35 },
  filtered: { catalog: 25, attached: 10 },
};

describe("Image Selection State Machine - Filter Helpers", () => {
  it("normalizes filters by trimming search and omitting empty / 'all' / undefined values", () => {
    assert.deepEqual(
      normalizeFilter({
        search: "  ubuntu  ",
        os_type: "all",
        protocol: "ALL",
        enabled: undefined,
      }),
      { search: "ubuntu" }
    );

    assert.deepEqual(
      normalizeFilter({
        search: "   ",
        os_type: "linux",
        protocol: "simplestreams",
        enabled: true,
      }),
      { os_type: "linux", protocol: "simplestreams", enabled: true }
    );

    assert.deepEqual(normalizeFilter(null), {});
    assert.deepEqual(normalizeFilter(undefined), {});
    assert.deepEqual(normalizeFilter({ search: "all" }), {});
  });

  it("checks whether a filter is empty", () => {
    assert.equal(isEmptyFilter({}), true);
    assert.equal(isEmptyFilter({ search: "   ", os_type: "all" }), true);
    assert.equal(isEmptyFilter({ search: "debian" }), false);
    assert.equal(isEmptyFilter({ enabled: false }), false);
    assert.equal(isEmptyFilter(null), true);
  });

  it("compares filter equality", () => {
    assert.equal(
      areFiltersEqual(
        { search: " debian ", os_type: "all" },
        { search: "debian" }
      ),
      true
    );
    assert.equal(
      areFiltersEqual(
        { os_type: "linux", enabled: true },
        { os_type: "linux", enabled: false }
      ),
      false
    );
  });

  it("matches catalog items against filters accurately", () => {
    // Empty filter matches everything
    assert.equal(matchesCatalogFilter(sampleLinux, {}), true);

    // Search substring on name (case-insensitive)
    assert.equal(matchesCatalogFilter(sampleLinux, { search: "UBUNTU" }), true);

    // Search substring on source
    assert.equal(matchesCatalogFilter(sampleLinux, { search: "24.04" }), true);
    assert.equal(matchesCatalogFilter(sampleLinux, { search: "debian" }), false);

    // Exact match on os_type
    assert.equal(matchesCatalogFilter(sampleLinux, { os_type: "linux" }), true);
    assert.equal(matchesCatalogFilter(sampleLinux, { os_type: "windows" }), false);

    // Exact match on protocol
    assert.equal(matchesCatalogFilter(sampleWindows, { protocol: "direct" }), true);
    assert.equal(matchesCatalogFilter(sampleWindows, { protocol: "simplestreams" }), false);

    // Exact match on enabled status
    assert.equal(matchesCatalogFilter(sampleBsdDisabled, { enabled: false }), true);
    assert.equal(matchesCatalogFilter(sampleBsdDisabled, { enabled: true }), false);
  });
});

describe("Image Selection State Machine - Create Flow", () => {
  it("initial selection starts at mode all with no staged changes", () => {
    const state = createInitialSelection("create");
    assert.equal(state.base.kind, "all");
    assert.equal(state.filterOps.length, 0);
    assert.equal(state.includeIds.size, 0);
    assert.equal(state.excludeIds.size, 0);

    assert.equal(hasStagedChanges(state, "create"), false);
    assert.equal(isImageSelected(state, sampleLinux), true);
    assert.equal(isImageSelected(state, sampleWindows), true);
    assert.deepEqual(toSelectionPayload(state, "create"), { mode: "all" });
  });

  it("toggling individual item on/off populates and unpopulates excludeIds", () => {
    const initial = createInitialSelection("create");

    // Toggle sampleLinux off
    const { state: s1 } = toggleImage(initial, sampleLinux);
    assert.equal(isImageSelected(s1, sampleLinux), false);
    assert.equal(s1.excludeIds.has(sampleLinux.id), true);
    assert.equal(hasStagedChanges(s1, "create"), true);
    assert.deepEqual(toSelectionPayload(s1, "create"), {
      mode: "filter",
      filter: {},
      exclude_ids: [sampleLinux.id],
    });

    // Toggle sampleLinux back on -> removes from excludeIds and reverts to clean mode: all
    const { state: s2 } = toggleImage(s1, sampleLinux);
    assert.equal(isImageSelected(s2, sampleLinux), true);
    assert.equal(s2.excludeIds.size, 0);
    assert.equal(s2.includeIds.size, 0);
    assert.equal(hasStagedChanges(s2, "create"), false);
    assert.deepEqual(toSelectionPayload(s2, "create"), { mode: "all" });
  });

  it("clearing all in create flow produces mode selected when individual items are checked", () => {
    const initial = createInitialSelection("create");
    const { state: sCleared } = clearAllMatching(initial, {}, "create");

    assert.equal(sCleared.base.kind, "none");
    assert.equal(hasStagedChanges(sCleared, "create"), true);
    assert.equal(isImageSelected(sCleared, sampleLinux), false);

    // Save validation prevents saving 0 selected items when catalog has images
    assert.deepEqual(canSaveSelection(sCleared, sampleCounts), {
      valid: false,
      reason: "empty_selection",
    });

    // Toggle 1 image on
    const { state: sSelected } = toggleImage(sCleared, sampleLinux);
    assert.equal(isImageSelected(sSelected, sampleLinux), true);
    assert.equal(sSelected.includeIds.has(sampleLinux.id), true);
    assert.deepEqual(toSelectionPayload(sSelected, "create"), {
      mode: "selected",
      ids: [sampleLinux.id],
    });
    assert.deepEqual(canSaveSelection(sSelected, sampleCounts), { valid: true });
  });

  it("selecting all matching a filter from none sets base filter and produces mode filter", () => {
    const initial = createInitialSelection("create");
    const { state: sCleared } = clearAllMatching(initial, {}, "create");

    // Select all matching Linux
    const { state: sFilter } = selectAllMatching(sCleared, { os_type: "linux" }, "create");
    assert.equal(sFilter.base.kind, "filter");
    assert.equal(isImageSelected(sFilter, sampleLinux), true);
    assert.equal(isImageSelected(sFilter, sampleWindows), false);
    assert.deepEqual(toSelectionPayload(sFilter, "create"), {
      mode: "filter",
      filter: { os_type: "linux" },
      exclude_ids: [],
    });

    // Exclude 1 Linux image
    const { state: sWithExclude } = toggleImage(sFilter, sampleLinux);
    assert.equal(isImageSelected(sWithExclude, sampleLinux), false);
    assert.deepEqual(toSelectionPayload(sWithExclude, "create"), {
      mode: "filter",
      filter: { os_type: "linux" },
      exclude_ids: [sampleLinux.id],
    });
  });

  it("switches to delta mode on create flow when multiple filterOps or filter + includeIds exist", () => {
    const initial = createInitialSelection("create");
    const { state: sCleared } = clearAllMatching(initial, {}, "create");
    const { state: sFilter } = selectAllMatching(sCleared, { os_type: "linux" }, "create");

    // Toggle a windows image on (adds to includeIds on top of base filter)
    const { state: sCustom } = toggleImage(sFilter, sampleWindows);
    assert.deepEqual(toSelectionPayload(sCustom, "create"), {
      mode: "delta",
      filter_ops: [
        { action: "detach", filter: {} },
        { action: "attach", filter: { os_type: "linux" } },
      ],
      attach_ids: [sampleWindows.id],
      detach_ids: [],
    });
  });
});

describe("Image Selection State Machine - Edit Flow", () => {
  it("initial selection starts at current with no changes and returns undefined payload", () => {
    const state = createInitialSelection("edit");
    assert.equal(state.base.kind, "current");
    assert.equal(hasStagedChanges(state, "edit"), false);
    assert.equal(toSelectionPayload(state, "edit"), undefined);

    // Initial item selection reflects item.attached
    assert.equal(isImageSelected(state, sampleLinux), true);
    assert.equal(isImageSelected(state, sampleWindows), false);
  });

  it("toggling attached item stages detach_ids, and toggling back reverts to undefined", () => {
    const initial = createInitialSelection("edit");

    // Toggle attached Linux image off
    const { state: s1 } = toggleImage(initial, sampleLinux);
    assert.equal(isImageSelected(s1, sampleLinux), false);
    assert.equal(s1.excludeIds.has(sampleLinux.id), true);
    assert.equal(hasStagedChanges(s1, "edit"), true);
    assert.deepEqual(toSelectionPayload(s1, "edit"), {
      mode: "delta",
      filter_ops: [],
      attach_ids: [],
      detach_ids: [sampleLinux.id],
    });

    // Toggle attached Linux image back on
    const { state: s2 } = toggleImage(s1, sampleLinux);
    assert.equal(isImageSelected(s2, sampleLinux), true);
    assert.equal(s2.excludeIds.size, 0);
    assert.equal(s2.includeIds.size, 0);
    assert.equal(hasStagedChanges(s2, "edit"), false);
    assert.equal(toSelectionPayload(s2, "edit"), undefined);
  });

  it("toggling unattached item stages attach_ids, and toggling back reverts to undefined", () => {
    const initial = createInitialSelection("edit");

    // Toggle unattached Windows image on
    const { state: s1 } = toggleImage(initial, sampleWindows);
    assert.equal(isImageSelected(s1, sampleWindows), true);
    assert.equal(s1.includeIds.has(sampleWindows.id), true);
    assert.equal(hasStagedChanges(s1, "edit"), true);
    assert.deepEqual(toSelectionPayload(s1, "edit"), {
      mode: "delta",
      filter_ops: [],
      attach_ids: [sampleWindows.id],
      detach_ids: [],
    });

    // Toggle unattached Windows image back off
    const { state: s2 } = toggleImage(s1, sampleWindows);
    assert.equal(isImageSelected(s2, sampleWindows), false);
    assert.equal(s2.includeIds.size, 0);
    assert.equal(hasStagedChanges(s2, "edit"), false);
    assert.equal(toSelectionPayload(s2, "edit"), undefined);
  });

  it("bulk select all in catalog in edit flow yields mode all", () => {
    const initial = createInitialSelection("edit");
    const { state: sAll } = selectAllMatching(initial, {}, "edit");

    assert.equal(sAll.base.kind, "all");
    assert.equal(hasStagedChanges(sAll, "edit"), true);
    assert.deepEqual(toSelectionPayload(sAll, "edit"), { mode: "all" });
  });

  it("bulk clear all in catalog in edit flow stages detach all filter op", () => {
    const initial = createInitialSelection("edit");
    const { state: sCleared } = clearAllMatching(initial, {}, "edit");

    assert.equal(sCleared.base.kind, "current");
    assert.deepEqual(sCleared.filterOps, [{ action: "detach", filter: {} }]);
    assert.deepEqual(toSelectionPayload(sCleared, "edit"), {
      mode: "delta",
      filter_ops: [{ action: "detach", filter: {} }],
      attach_ids: [],
      detach_ids: [],
    });
  });
});

describe("Image Selection State Machine - Bulk Actions & FilterOps Deduplication", () => {
  it("deduplicates identical consecutive filter operations", () => {
    const initial = createInitialSelection("edit");
    const filter: ImageCatalogFilter = { os_type: "linux" };

    const { state: s1 } = selectAllMatching(initial, filter, "edit");
    assert.equal(s1.filterOps.length, 1);
    assert.equal(s1.filterOps[0].action, "attach");

    // Applying same attach filter again does not duplicate op
    const { state: s2 } = selectAllMatching(s1, filter, "edit");
    assert.equal(s2.filterOps.length, 1);

    // Applying detach on same filter replaces the last op
    const { state: s3 } = clearAllMatching(s2, filter, "edit");
    assert.equal(s3.filterOps.length, 1);
    assert.equal(s3.filterOps[0].action, "detach");
  });

  it("clears manual overrides when bulk matching filter is applied", () => {
    const initial = createInitialSelection("create");
    const { state: sWithOverrides } = toggleImage(initial, sampleLinux);
    assert.equal(sWithOverrides.excludeIds.size, 1);

    const { state: sBulk } = selectAllMatching(sWithOverrides, { os_type: "linux" }, "create");
    assert.equal(sBulk.excludeIds.size, 0);
    assert.equal(sBulk.includeIds.size, 0);
  });
});

describe("Image Selection State Machine - Cap Enforcement", () => {
  it("enforces max 500 ID overrides for includeIds and excludeIds", () => {
    let state = createInitialSelection("create");

    // Create a state with 500 exclude IDs
    const excludeSet = new Set<number>();
    for (let i = 1000; i < 1500; i++) {
      excludeSet.add(i);
    }
    state = { ...state, excludeIds: excludeSet };

    // Attempting to toggle 501st item off returns limitReached: true
    const result = toggleImage(state, sampleLinux);
    assert.equal(result.limitReached, true);
    assert.equal(result.state.excludeIds.size, MAX_ID_OVERRIDES);
    assert.equal(result.state.excludeIds.has(sampleLinux.id), false);
  });

  it("enforces max 10 filter operations cap", () => {
    let state = createInitialSelection("edit");

    for (let i = 1; i <= MAX_FILTER_OPS; i++) {
      const { state: nextState, limitReached } = selectAllMatching(
        state,
        { search: `query-${i}` },
        "edit"
      );
      assert.equal(limitReached, false);
      state = nextState;
    }
    assert.equal(state.filterOps.length, MAX_FILTER_OPS);

    // 11th distinct filter op should hit the limit
    const overflowResult = selectAllMatching(state, { search: "query-11" }, "edit");
    assert.equal(overflowResult.limitReached, true);
    assert.equal(overflowResult.state.filterOps.length, MAX_FILTER_OPS);
  });
});

describe("Image Selection State Machine - Selected Count Estimation", () => {
  it("handles null counts gracefully", () => {
    const state = createInitialSelection("create");
    const est = estimateSelectedCount(state, null);
    assert.deepEqual(est, { selected: 0, total: 0, isApproximate: false });
  });

  it("calculates accurate count for base all minus excludeIds", () => {
    let state = createInitialSelection("create");
    state = toggleImage(state, sampleLinux).state; // excludes id 1
    state = toggleImage(state, sampleWindows).state; // excludes id 2

    const est = estimateSelectedCount(state, sampleCounts);
    assert.equal(est.selected, 98);
    assert.equal(est.total, 100);
    assert.equal(est.isApproximate, false);
  });

  it("calculates accurate count for base none plus includeIds", () => {
    let state = createInitialSelection("create");
    state = clearAllMatching(state, {}, "create").state;
    state = toggleImage(state, sampleLinux).state; // includes id 1

    const est = estimateSelectedCount(state, sampleCounts);
    assert.equal(est.selected, 1);
    assert.equal(est.total, 100);
    assert.equal(est.isApproximate, false);
  });

  it("calculates accurate count for base current with manual adjustments", () => {
    let state = createInitialSelection("edit"); // attached = 35
    state = toggleImage(state, sampleWindows).state; // includes id 2 (attached: false -> true)

    const est = estimateSelectedCount(state, sampleCounts);
    assert.equal(est.selected, 36);
    assert.equal(est.total, 100);
    assert.equal(est.isApproximate, false);
  });

  it("calculates accurate count when matching filteredAdjustment is supplied", () => {
    const filter: ImageCatalogFilter = { os_type: "linux" };
    let state = createInitialSelection("create");
    state = clearAllMatching(state, {}, "create").state;
    state = selectAllMatching(state, filter, "create").state;

    const est = estimateSelectedCount(state, sampleCounts, { filter });
    assert.equal(est.selected, 25);
    assert.equal(est.total, 100);
    assert.equal(est.isApproximate, false);
  });

  it("calculates accurate count for single detach-all op in edit flow", () => {
    let state = createInitialSelection("edit");
    state = clearAllMatching(state, {}, "edit").state;
    state = toggleImage(state, sampleLinux).state; // checked 1 row

    const est = estimateSelectedCount(state, sampleCounts);
    assert.equal(est.selected, 1);
    assert.equal(est.total, 100);
    assert.equal(est.isApproximate, false);
  });

  it("marks isApproximate true when multiple or non-empty filterOps are present", () => {
    let state = createInitialSelection("edit");
    state = selectAllMatching(state, { os_type: "linux" }, "edit").state;

    const est = estimateSelectedCount(state, sampleCounts);
    assert.equal(est.isApproximate, true);
    assert.equal(est.total, 100);
    assert.ok(est.selected >= 0 && est.selected <= 100);
  });
});
