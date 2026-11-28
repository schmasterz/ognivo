import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  splitRowActions,
  type SplittableAction,
} from "../../../../src/components/ui/rowActions/splitRowActions.ts";

function ids(items: readonly SplittableAction[]): string[] {
  return items.map((item) => item.id);
}

describe("splitRowActions", () => {
  it("returns empty arrays and null divider for empty input without mutating input", () => {
    const actions: readonly SplittableAction[] = [];
    const result = splitRowActions(actions);
    assert.deepEqual(result, {
      inline: [],
      menu: [],
      dividerBeforeId: null,
    });
  });

  it("does not mutate the input actions array", () => {
    const actions: SplittableAction[] = [
      { id: "delete", severity: "critical" },
      { id: "restart", severity: "disruptive" },
      { id: "start", severity: "caution" },
      { id: "details", severity: "safe" },
    ];
    const snapshot = actions.map((action) => ({ ...action }));

    splitRowActions(actions);

    assert.deepEqual(actions, snapshot);
  });

  it("drops actions where hidden is true", () => {
    const actions: SplittableAction[] = [
      { id: "hidden-safe", severity: "safe", hidden: true },
      { id: "visible-safe", severity: "safe" },
      { id: "hidden-caution", severity: "caution", hidden: true },
      { id: "hidden-disruptive", severity: "disruptive", hidden: true },
      { id: "hidden-critical", severity: "critical", hidden: true },
    ];

    const result = splitRowActions(actions);

    assert.deepEqual(ids(result.inline), ["visible-safe"]);
    assert.deepEqual(ids(result.menu), []);
    assert.equal(result.dividerBeforeId, null);
  });

  it("never places disruptive or critical actions inline even when maxVisible is large", () => {
    const actions: SplittableAction[] = [
      { id: "details", severity: "safe" },
      { id: "restart", severity: "disruptive" },
      { id: "stop", severity: "disruptive" },
      { id: "delete", severity: "critical" },
    ];

    const result = splitRowActions(actions, 10);

    assert.deepEqual(ids(result.inline), ["details"]);
    assert.deepEqual(ids(result.menu), ["restart", "stop", "delete"]);
    assert.equal(result.dividerBeforeId, "delete");
  });

  it("selects inline candidates by lowest severity first while preserving array order on ties", () => {
    const actions: SplittableAction[] = [
      { id: "caution-1", severity: "caution" },
      { id: "safe-1", severity: "safe" },
      { id: "caution-2", severity: "caution" },
      { id: "safe-2", severity: "safe" },
    ];

    const result = splitRowActions(actions, 2);

    assert.deepEqual(ids(result.inline), ["safe-1", "safe-2"]);
    assert.deepEqual(ids(result.menu), ["caution-1", "caution-2"]);
    assert.equal(result.dividerBeforeId, null);
  });

  it("places one safe and one caution inline when only those two exist", () => {
    const actions: SplittableAction[] = [
      { id: "impersonate", severity: "caution" },
      { id: "edit", severity: "safe" },
    ];

    const result = splitRowActions(actions);

    assert.deepEqual(ids(result.inline), ["edit", "impersonate"]);
    assert.deepEqual(ids(result.menu), []);
    assert.equal(result.dividerBeforeId, null);
  });

  it("places first two of three safe actions inline and puts the third in the menu", () => {
    const actions: SplittableAction[] = [
      { id: "console", severity: "safe" },
      { id: "details", severity: "safe" },
      { id: "snapshots", severity: "safe" },
    ];

    const result = splitRowActions(actions);

    assert.deepEqual(ids(result.inline), ["console", "details"]);
    assert.deepEqual(ids(result.menu), ["snapshots"]);
    assert.equal(result.dividerBeforeId, null);
  });

  it("sorts menu by severity ascending and preserves original order within each level", () => {
    const actions: SplittableAction[] = [
      { id: "crit-1", severity: "critical" },
      { id: "disr-1", severity: "disruptive" },
      { id: "caut-1", severity: "caution" },
      { id: "safe-1", severity: "safe" },
      { id: "crit-2", severity: "critical" },
      { id: "safe-2", severity: "safe" },
      { id: "disr-2", severity: "disruptive" },
      { id: "caut-2", severity: "caution" },
      { id: "safe-3", severity: "safe" },
    ];

    const result = splitRowActions(actions, 2);

    assert.deepEqual(ids(result.inline), ["safe-1", "safe-2"]);
    assert.deepEqual(ids(result.menu), [
      "safe-3",
      "caut-1",
      "caut-2",
      "disr-1",
      "disr-2",
      "crit-1",
      "crit-2",
    ]);
    assert.equal(result.dividerBeforeId, "crit-1");
  });

  it("sets dividerBeforeId only when a critical item is preceded by at least one menu item", () => {
    const withPreceding = splitRowActions([
      { id: "stop", severity: "disruptive" },
      { id: "delete", severity: "critical" },
      { id: "purge", severity: "critical" },
    ]);
    assert.equal(withPreceding.dividerBeforeId, "delete");

    const criticalFirst = splitRowActions([
      { id: "restore", severity: "critical" },
      { id: "delete", severity: "critical" },
    ]);
    assert.equal(criticalFirst.dividerBeforeId, null);

    const noCritical = splitRowActions([
      { id: "edit", severity: "safe" },
      { id: "start", severity: "caution" },
      { id: "restart", severity: "disruptive" },
    ]);
    assert.equal(noCritical.dividerBeforeId, null);
  });

  it("puts all non-hidden items into menu when maxVisible is 0, negative, or non-finite", () => {
    const actions: SplittableAction[] = [
      { id: "edit", severity: "safe" },
      { id: "start", severity: "caution" },
      { id: "delete", severity: "critical" },
    ];

    for (const maxVisible of [0, -1, Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY]) {
      const result = splitRowActions(actions, maxVisible);
      assert.deepEqual(ids(result.inline), []);
      assert.deepEqual(ids(result.menu), ["edit", "start", "delete"]);
      assert.equal(result.dividerBeforeId, "delete");
    }
  });

  it("floors positive finite fractional maxVisible values", () => {
    const actions: SplittableAction[] = [
      { id: "a", severity: "safe" },
      { id: "b", severity: "safe" },
      { id: "c", severity: "safe" },
    ];

    const result = splitRowActions(actions, 1.9);
    assert.deepEqual(ids(result.inline), ["a"]);
    assert.deepEqual(ids(result.menu), ["b", "c"]);
  });

  describe("real-table scenarios", () => {
    it("1. Running VM", () => {
      const actions: SplittableAction[] = [
        { id: "console", severity: "safe" },
        { id: "details", severity: "safe" },
        { id: "snapshots", severity: "safe" },
        { id: "start", severity: "caution", hidden: true },
        { id: "restart", severity: "disruptive" },
        { id: "suspend", severity: "disruptive" },
        { id: "stop", severity: "disruptive" },
        { id: "delete", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), ["console", "details"]);
      assert.deepEqual(ids(result.menu), [
        "snapshots",
        "restart",
        "suspend",
        "stop",
        "delete",
      ]);
      assert.equal(result.dividerBeforeId, "delete");
    });

    it("2. Stopped VM", () => {
      const actions: SplittableAction[] = [
        { id: "console", severity: "safe" },
        { id: "details", severity: "safe" },
        { id: "snapshots", severity: "safe" },
        { id: "start", severity: "caution" },
        { id: "restart", severity: "disruptive" },
        { id: "suspend", severity: "disruptive", hidden: true },
        { id: "stop", severity: "disruptive", hidden: true },
        { id: "delete", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), ["console", "details"]);
      assert.deepEqual(ids(result.menu), [
        "snapshots",
        "start",
        "restart",
        "delete",
      ]);
      assert.equal(result.dividerBeforeId, "delete");
    });

    it("3. Active eligible user", () => {
      const actions: SplittableAction[] = [
        { id: "edit", severity: "safe" },
        { id: "impersonate", severity: "caution" },
        { id: "activate", severity: "caution", hidden: true },
        { id: "suspend", severity: "disruptive" },
        { id: "delete", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), ["edit", "impersonate"]);
      assert.deepEqual(ids(result.menu), ["suspend", "delete"]);
      assert.equal(result.dividerBeforeId, "delete");
    });

    it("4. Suspended user", () => {
      const actions: SplittableAction[] = [
        { id: "edit", severity: "safe" },
        { id: "impersonate", severity: "caution", hidden: true },
        { id: "activate", severity: "caution" },
        { id: "suspend", severity: "disruptive", hidden: true },
        { id: "delete", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), ["edit", "activate"]);
      assert.deepEqual(ids(result.menu), ["delete"]);
      assert.equal(result.dividerBeforeId, null);
    });

    it("5. Snapshot row", () => {
      const actions: SplittableAction[] = [
        { id: "restore", severity: "critical" },
        { id: "delete", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), []);
      assert.deepEqual(ids(result.menu), ["restore", "delete"]);
      assert.equal(result.dividerBeforeId, null);
    });

    it("6. IP row", () => {
      const actions: SplittableAction[] = [
        { id: "release", severity: "critical" },
      ];

      const result = splitRowActions(actions);

      assert.deepEqual(ids(result.inline), []);
      assert.deepEqual(ids(result.menu), ["release"]);
      assert.equal(result.dividerBeforeId, null);
    });
  });
});
