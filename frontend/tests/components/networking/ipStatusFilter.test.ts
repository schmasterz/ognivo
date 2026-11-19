import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { normalizeIpStatusFilter } from "../../../src/components/networking/ipStatusFilter.ts";

describe("normalizeIpStatusFilter", () => {
  it("passes through allowed IP statuses", () => {
    assert.equal(normalizeIpStatusFilter("allocated"), "allocated");
    assert.equal(normalizeIpStatusFilter("cooling"), "cooling");
  });

  it("returns null for unsupported VM, user, empty, or released statuses", () => {
    for (const value of [
      "running",
      "active",
      "",
      null,
      undefined,
      "released",
    ]) {
      assert.equal(normalizeIpStatusFilter(value), null);
    }
  });
});
