import { describe, it } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import en from "../locale/en/index.ts";
import bg from "../locale/bg/index.ts";

const locales = { en, bg } as const;

describe("i18n locale parity & integrity", () => {
  it("ensures every locale has exactly the same key set as en", () => {
    const enKeys = Object.keys(locales.en).sort();

    for (const [locale, dict] of Object.entries(locales)) {
      if (locale === "en") continue;
      const localeKeys = Object.keys(dict).sort();
      assert.deepStrictEqual(localeKeys, enKeys, `${locale} key set differs from en`);
    }
  });

  it("ensures no duplicate keys across section files within each locale", async () => {
    for (const locale of Object.keys(locales)) {
      const localeDir = path.resolve(import.meta.dirname, `../locale/${locale}`);
      const files = fs
        .readdirSync(localeDir)
        .filter((f) => f.endsWith(".ts") && f !== "index.ts");

      const seenKeys = new Map<string, string>();
      for (const file of files) {
        const modulePath = path.join(localeDir, file);
        const mod = await import(modulePath);
        const sectionObj = mod.default as Record<string, string>;
        assert.ok(sectionObj && typeof sectionObj === "object", `${file} does not export default object`);

        for (const key of Object.keys(sectionObj)) {
          if (seenKeys.has(key)) {
            assert.fail(
              `Duplicate key "${key}" found in ${locale}/${file} (already defined in ${locale}/${seenKeys.get(key)})`
            );
          }
          seenKeys.set(key, file);
        }
      }
    }
  });
});
