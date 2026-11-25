import { describe, it, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
  setThemeTokens,
  getThemeTokens,
  setBrandLogo,
  getBrandLogo,
  subscribeThemeRegistry,
  clearThemeRegistry,
  type BrandLogoComponent,
} from "../../../src/lib/theming/themeRegistry.ts";

const makeDummyLogo = (label: string): BrandLogoComponent => {
  const DummyLogo: BrandLogoComponent = ({ className }) => {
    void className;
    return null;
  };
  DummyLogo.displayName = label;
  return DummyLogo;
};

describe("themeRegistry", () => {
  beforeEach(() => {
    clearThemeRegistry();
  });

  it("sets, merges, and gets theme tokens", () => {
    assert.deepEqual(getThemeTokens(), {});

    setThemeTokens({ primaryColor: "#2563eb" });
    assert.deepEqual(getThemeTokens(), { primaryColor: "#2563eb" });

    setThemeTokens({ accentColor: "#f47721", borderRadius: "0.5rem" });
    assert.deepEqual(getThemeTokens(), {
      primaryColor: "#2563eb",
      accentColor: "#f47721",
      borderRadius: "0.5rem",
    });

    setThemeTokens({ primaryColor: "#1d4ed8" });
    assert.deepEqual(getThemeTokens(), {
      primaryColor: "#1d4ed8",
      accentColor: "#f47721",
      borderRadius: "0.5rem",
    });

    // Returned snapshot should not allow mutating internal registry state
    const snapshot = getThemeTokens();
    snapshot.primaryColor = "#000000";
    assert.equal(getThemeTokens().primaryColor, "#1d4ed8");
  });

  it("sets and gets custom brand logo component", () => {
    assert.equal(getBrandLogo(), null);

    const CustomLogo = makeDummyLogo("CustomBrandLogo");
    setBrandLogo(CustomLogo);
    assert.equal(getBrandLogo(), CustomLogo);

    const ReplacementLogo = makeDummyLogo("ReplacementBrandLogo");
    setBrandLogo(ReplacementLogo);
    assert.equal(getBrandLogo(), ReplacementLogo);

    setBrandLogo(null);
    assert.equal(getBrandLogo(), null);
  });

  it("triggers subscription callbacks on changes and stops after unsubscribe", () => {
    let callCount = 0;
    const unsubscribe = subscribeThemeRegistry(() => {
      callCount += 1;
    });

    setThemeTokens({ primaryColor: "#2563eb" });
    assert.equal(callCount, 1);

    setBrandLogo(makeDummyLogo("SubscribedLogo"));
    assert.equal(callCount, 2);

    clearThemeRegistry();
    assert.equal(callCount, 3);

    unsubscribe();

    setThemeTokens({ accentColor: "#10b981" });
    setBrandLogo(makeDummyLogo("IgnoredLogo"));
    assert.equal(callCount, 3);
  });

  it("clearThemeRegistry resets tokens and brand logo", () => {
    setThemeTokens({
      primaryColor: "#2563eb",
      accentColor: "#f47721",
      borderRadius: "0.75rem",
    });
    setBrandLogo(makeDummyLogo("TempLogo"));

    clearThemeRegistry();

    assert.deepEqual(getThemeTokens(), {});
    assert.equal(getBrandLogo(), null);
  });
});
