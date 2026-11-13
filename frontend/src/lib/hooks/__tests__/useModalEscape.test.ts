import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  registerModalEscape,
  dispatchModalEscape,
  getModalEscapeStackDepth,
} from "../useModalEscape.ts";

describe("useModalEscape Stack Management Tests", () => {
  it("registers and unregisters modal escape handlers correctly", () => {
    let closed = false;
    const unregister = registerModalEscape(() => {
      closed = true;
    });

    assert.equal(getModalEscapeStackDepth(), 1);
    const handled = dispatchModalEscape();
    assert.equal(handled, true);
    assert.equal(closed, true);

    unregister();
    assert.equal(getModalEscapeStackDepth(), 0);
    const unhandled = dispatchModalEscape();
    assert.equal(unhandled, false);
  });

  it("handles stacked modals by closing only the topmost modal first (LIFO)", () => {
    const closedOrder: string[] = [];

    const unregisterModalA = registerModalEscape(() => {
      closedOrder.push("modalA");
    });
    const unregisterModalB = registerModalEscape(() => {
      closedOrder.push("modalB");
    });
    const unregisterModalC = registerModalEscape(() => {
      closedOrder.push("modalC");
    });

    assert.equal(getModalEscapeStackDepth(), 3);

    // First Esc -> closes Modal C
    dispatchModalEscape();
    unregisterModalC();
    assert.deepEqual(closedOrder, ["modalC"]);
    assert.equal(getModalEscapeStackDepth(), 2);

    // Second Esc -> closes Modal B
    dispatchModalEscape();
    unregisterModalB();
    assert.deepEqual(closedOrder, ["modalC", "modalB"]);
    assert.equal(getModalEscapeStackDepth(), 1);

    // Third Esc -> closes Modal A
    dispatchModalEscape();
    unregisterModalA();
    assert.deepEqual(closedOrder, ["modalC", "modalB", "modalA"]);
    assert.equal(getModalEscapeStackDepth(), 0);
  });
});
