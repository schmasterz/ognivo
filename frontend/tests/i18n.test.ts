import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { interpolate } from "../src/lib/i18n/interpolate.ts";
import { resolveLocale } from "../src/lib/i18n/resolve-locale.ts";
import { DEFAULT_LOCALE } from "../src/lib/i18n/loaders.ts";

describe("i18n safe interpolation", () => {
  it('inserts "$&" and "$1" literally without regex replacement side effects', () => {
    assert.strictEqual(
      interpolate("Price is :val", { val: "$&" }),
      "Price is $&"
    );
    assert.strictEqual(
      interpolate("Target: :val", { val: "$1" }),
      "Target: $1"
    );
    assert.strictEqual(
      interpolate("Replacing :a with :b", { a: "$`", b: "$'" }),
      "Replacing $` with $'"
    );
  });

  it("replaces :count and :countries correctly without :count corrupting :countries", () => {
    // When both are provided in any object key order, longest keys first ensures :countries is replaced before :count
    const resBoth = interpolate("Found :count instances across :countries countries", {
      count: 42,
      countries: 5,
    });
    assert.strictEqual(resBoth, "Found 42 instances across 5 countries");

    const resReversed = interpolate("Found :count instances across :countries countries", {
      countries: 5,
      count: 42,
    });
    assert.strictEqual(resReversed, "Found 42 instances across 5 countries");

    const resAdjacent = interpolate(":countries :count", {
      count: 1,
      countries: 2,
    });
    assert.strictEqual(resAdjacent, "2 1");
  });

  it('handles suffixed placeholders like ":secondss" and ":secondsс"', () => {
    assert.strictEqual(
      interpolate("Retry in :secondss", { seconds: 5 }),
      "Retry in 5s"
    );
    assert.strictEqual(
      interpolate("Опитайте след :secondsс", { seconds: 5 }),
      "Опитайте след 5с"
    );
  });

  it("leaves untouched placeholders when params are missing or undefined", () => {
    assert.strictEqual(interpolate("Hello :name"), "Hello :name");
    assert.strictEqual(
      interpolate("Hello :name and :role", { name: "Alice" }),
      "Hello Alice and :role"
    );
  });
});

describe("i18n server resolveLocale", () => {
  it("a valid cookie wins over Accept-Language", () => {
    assert.strictEqual(resolveLocale("en", "bg-BG,bg;q=0.9"), "en");
    assert.strictEqual(resolveLocale("bg", "en-US,en;q=0.9"), "bg");
  });

  it('"bg-BG,bg;q=0.9,en;q=0.8" with no cookie resolves to "bg"', () => {
    assert.strictEqual(
      resolveLocale(undefined, "bg-BG,bg;q=0.9,en;q=0.8"),
      "bg"
    );
  });

  it('"de-DE,de;q=0.9" with no match falls back to DEFAULT_LOCALE', () => {
    assert.strictEqual(resolveLocale(undefined, "de-DE,de;q=0.9"), DEFAULT_LOCALE);
  });

  it("empty or null header falls back to DEFAULT_LOCALE", () => {
    assert.strictEqual(resolveLocale(undefined, null), DEFAULT_LOCALE);
    assert.strictEqual(resolveLocale(undefined, ""), DEFAULT_LOCALE);
    assert.strictEqual(resolveLocale(undefined, "   "), DEFAULT_LOCALE);
  });

  it('an invalid cookie value (e.g. "xx") is ignored and Accept-Language is used', () => {
    assert.strictEqual(
      resolveLocale("xx", "bg-BG,bg;q=0.9,en;q=0.8"),
      "bg"
    );
    assert.strictEqual(resolveLocale("xx", null), DEFAULT_LOCALE);
  });
});
