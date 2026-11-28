import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  computeMenuPlacement,
  MENU_DIVIDER_HEIGHT,
  MENU_ITEM_HEIGHT,
  MENU_MIN_MAX_HEIGHT,
  MENU_VERTICAL_PADDING,
  TRIGGER_GAP,
  VIEWPORT_PADDING,
} from "../../../../src/components/ui/rowActions/menuPlacement.ts";

describe("computeMenuPlacement", () => {
  it("opens below when there is sufficient room below the trigger", () => {
    const placement = computeMenuPlacement({
      triggerRect: { top: 100, bottom: 132, right: 1200 },
      viewportWidth: 1280,
      viewportHeight: 800,
      itemCount: 4,
      hasDivider: true,
    });

    const expectedSpaceBelow = 800 - 132 - TRIGGER_GAP - VIEWPORT_PADDING;

    assert.equal(placement.side, "bottom");
    assert.equal(placement.top, 132 + TRIGGER_GAP);
    assert.equal(placement.bottom, undefined);
    assert.equal(placement.right, 1280 - 1200);
    assert.equal(placement.maxHeight, expectedSpaceBelow);
  });

  it("flips above when below is too small and above has more room", () => {
    const placement = computeMenuPlacement({
      triggerRect: { top: 650, bottom: 682, right: 900 },
      viewportWidth: 1280,
      viewportHeight: 800,
      itemCount: 5,
      hasDivider: false,
    });

    const expectedSpaceAbove = 650 - TRIGGER_GAP - VIEWPORT_PADDING;

    assert.equal(placement.side, "top");
    assert.equal(placement.bottom, 800 - 650 + TRIGGER_GAP);
    assert.equal(placement.top, undefined);
    assert.equal(placement.right, 1280 - 900);
    assert.equal(placement.maxHeight, expectedSpaceAbove);
  });

  it("chooses the larger side when neither side fits and clamps maxHeight to MENU_MIN_MAX_HEIGHT", () => {
    // Very short viewport where neither side fits 5 items (188px) and both spaces are below MENU_MIN_MAX_HEIGHT
    const bottomLarger = computeMenuPlacement({
      triggerRect: { top: 40, bottom: 70, right: 500 },
      viewportWidth: 1000,
      viewportHeight: 150,
      itemCount: 5,
      hasDivider: false,
    });
    // spaceAbove = 40 - 4 - 8 = 28, spaceBelow = 150 - 70 - 4 - 8 = 68
    assert.equal(bottomLarger.side, "bottom");
    assert.equal(bottomLarger.top, 70 + TRIGGER_GAP);
    assert.equal(bottomLarger.bottom, undefined);
    assert.equal(bottomLarger.maxHeight, MENU_MIN_MAX_HEIGHT);

    const topLarger = computeMenuPlacement({
      triggerRect: { top: 90, bottom: 120, right: 500 },
      viewportWidth: 1000,
      viewportHeight: 150,
      itemCount: 5,
      hasDivider: false,
    });
    // spaceAbove = 90 - 4 - 8 = 78, spaceBelow = 150 - 120 - 4 - 8 = 18
    assert.equal(topLarger.side, "top");
    assert.equal(topLarger.bottom, 150 - 90 + TRIGGER_GAP);
    assert.equal(topLarger.top, undefined);
    assert.equal(topLarger.maxHeight, MENU_MIN_MAX_HEIGHT);
  });

  it("clamps right to VIEWPORT_PADDING when trigger is near or past the right edge", () => {
    const nearEdge = computeMenuPlacement({
      triggerRect: { top: 100, bottom: 132, right: 1277 },
      viewportWidth: 1280,
      viewportHeight: 800,
      itemCount: 2,
      hasDivider: false,
    });
    assert.equal(nearEdge.right, VIEWPORT_PADDING);

    const pastEdge = computeMenuPlacement({
      triggerRect: { top: 100, bottom: 132, right: 1310 },
      viewportWidth: 1280,
      viewportHeight: 800,
      itemCount: 2,
      hasDivider: false,
    });
    assert.equal(pastEdge.right, VIEWPORT_PADDING);
  });

  it("accounts for MENU_DIVIDER_HEIGHT when hasDivider is true at the exact threshold", () => {
    const itemCount = 4;
    const heightWithoutDivider =
      itemCount * MENU_ITEM_HEIGHT + MENU_VERTICAL_PADDING;
    // Set spaceBelow === heightWithoutDivider exactly, with much larger spaceAbove
    const triggerBottom = 400;
    const triggerTop = 368;
    const viewportHeight =
      triggerBottom + TRIGGER_GAP + VIEWPORT_PADDING + heightWithoutDivider;

    const withoutDivider = computeMenuPlacement({
      triggerRect: { top: triggerTop, bottom: triggerBottom, right: 500 },
      viewportWidth: 1000,
      viewportHeight,
      itemCount,
      hasDivider: false,
    });
    assert.equal(withoutDivider.side, "bottom");

    const withDivider = computeMenuPlacement({
      triggerRect: { top: triggerTop, bottom: triggerBottom, right: 500 },
      viewportWidth: 1000,
      viewportHeight,
      itemCount,
      hasDivider: true,
    });
    assert.equal(withDivider.side, "top");

    // Adding MENU_DIVIDER_HEIGHT to viewportHeight makes hasDivider: true fit below again
    const withDividerFits = computeMenuPlacement({
      triggerRect: { top: triggerTop, bottom: triggerBottom, right: 500 },
      viewportWidth: 1000,
      viewportHeight: viewportHeight + MENU_DIVIDER_HEIGHT,
      itemCount,
      hasDivider: true,
    });
    assert.equal(withDividerFits.side, "bottom");
  });
});
