import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { nextCoolingDeadlineMs, nextCoolingRefreshDelayMs } from "./coolingRefresh.ts";

describe("nextCoolingRefreshDelayMs", () => {
  it("returns null for an empty list", () => {
    assert.equal(nextCoolingRefreshDelayMs([]), null);
  });

  it("returns null when there are no cooling rows", () => {
    assert.equal(
      nextCoolingRefreshDelayMs([
        { status: "allocated", cooldown_remaining_seconds: 10 },
        { status: "released", cooldown_remaining_seconds: 0 },
        { status: "released", cooldown_remaining_seconds: 5 },
      ]),
      null,
    );
  });

  it("picks the smallest positive remaining seconds * 1000 + 1000 across mixed rows", () => {
    assert.equal(
      nextCoolingRefreshDelayMs([
        { status: "allocated", cooldown_remaining_seconds: 1 },
        { status: "cooling", cooldown_remaining_seconds: 42 },
        { status: "cooling", cooldown_remaining_seconds: 7 },
        { status: "cooling", cooldown_remaining_seconds: 15 },
        { status: "released", cooldown_remaining_seconds: 0 },
      ]),
      8000,
    );
  });

  it("ignores cooling rows with remaining <= 0, null, or undefined", () => {
    assert.equal(
      nextCoolingRefreshDelayMs([
        { status: "cooling", cooldown_remaining_seconds: 0 },
        { status: "cooling", cooldown_remaining_seconds: -5 },
        { status: "cooling", cooldown_remaining_seconds: null },
        { status: "cooling", cooldown_remaining_seconds: undefined },
        { status: "cooling" },
      ]),
      null,
    );

    assert.equal(
      nextCoolingRefreshDelayMs([
        { status: "cooling", cooldown_remaining_seconds: 0 },
        { status: "cooling", cooldown_remaining_seconds: null },
        { status: "cooling", cooldown_remaining_seconds: 3 },
      ]),
      4000,
    );
  });

  it("ignores released rows with remaining 0", () => {
    assert.equal(
      nextCoolingRefreshDelayMs([
        { status: "released", cooldown_remaining_seconds: 0 },
      ]),
      null,
    );
  });
});

describe("nextCoolingDeadlineMs", () => {
  it("returns null when no cooling row qualifies", () => {
    assert.equal(nextCoolingDeadlineMs([], 1_700_000_000_000), null);
    assert.equal(
      nextCoolingDeadlineMs([{ status: "allocated" }], 1_700_000_000_000),
      null,
    );
  });

  it("adds the computed delay to nowMs", () => {
    const now = 1_700_000_000_000;
    const deadline = nextCoolingDeadlineMs(
      [
        { status: "cooling", cooldown_remaining_seconds: 12 },
        { status: "cooling", cooldown_remaining_seconds: 5 },
      ],
      now,
    );
    assert.equal(deadline, now + 6000);
  });
});
