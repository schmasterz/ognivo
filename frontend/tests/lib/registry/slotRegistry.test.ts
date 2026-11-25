import { describe, it, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
  registerSlotComponent,
  getSlotComponents,
  unregisterSlotComponent,
  clearSlotRegistry,
  type SlotId,
} from "../../../src/lib/registry/slotRegistry.ts";

const makeDummyComponent = (label: string) => {
  const DummyComponent = () => null;
  DummyComponent.displayName = label;
  return DummyComponent;
};

describe("slotRegistry", () => {
  beforeEach(() => {
    clearSlotRegistry();
  });

  it("registers items into different standard and custom slots without cross-contamination", () => {
    const TabComp = makeDummyComponent("TabComp");
    const WidgetComp = makeDummyComponent("WidgetComp");
    const CustomComp = makeDummyComponent("CustomComp");

    registerSlotComponent("instance.detail.tabs", {
      id: "metrics-tab",
      component: TabComp,
      metadata: { label: "Metrics" },
    });
    registerSlotComponent("dashboard.widgets", {
      id: "status-widget",
      component: WidgetComp,
    });
    const customSlot: SlotId = "custom.extension.footer";
    registerSlotComponent(customSlot, {
      id: "footer-banner",
      component: CustomComp,
    });

    const tabs = getSlotComponents("instance.detail.tabs");
    const widgets = getSlotComponents("dashboard.widgets");
    const custom = getSlotComponents(customSlot);
    const empty = getSlotComponents("login.providers");

    assert.equal(tabs.length, 1);
    assert.equal(tabs[0].id, "metrics-tab");
    assert.equal(tabs[0].component, TabComp);
    assert.deepEqual(tabs[0].metadata, { label: "Metrics" });

    assert.equal(widgets.length, 1);
    assert.equal(widgets[0].id, "status-widget");
    assert.equal(widgets[0].component, WidgetComp);

    assert.equal(custom.length, 1);
    assert.equal(custom[0].id, "footer-banner");

    assert.deepEqual(empty, []);
  });

  it("returns items sorted ascending by priority with default priority 100", () => {
    registerSlotComponent("settings.sections", {
      id: "default-priority",
      component: makeDummyComponent("Default"),
    });
    registerSlotComponent("settings.sections", {
      id: "low-priority",
      priority: 200,
      component: makeDummyComponent("Low"),
    });
    registerSlotComponent("settings.sections", {
      id: "mid-priority",
      priority: 50,
      component: makeDummyComponent("Mid"),
    });
    registerSlotComponent("settings.sections", {
      id: "high-priority",
      priority: 10,
      component: makeDummyComponent("High"),
    });

    const items = getSlotComponents("settings.sections");
    assert.deepEqual(
      items.map((item) => item.id),
      ["high-priority", "mid-priority", "default-priority", "low-priority"]
    );
  });

  it("replaces existing item when registering duplicate id in the same slot", () => {
    const FirstComp = makeDummyComponent("First");
    const ReplacementComp = makeDummyComponent("Replacement");

    registerSlotComponent("instance.detail.actions", {
      id: "reboot",
      priority: 50,
      component: FirstComp,
      metadata: { version: 1 },
    });

    registerSlotComponent("instance.detail.actions", {
      id: "reboot",
      priority: 10,
      component: ReplacementComp,
      metadata: { version: 2 },
    });

    // Registering same id in a different slot stays separate
    registerSlotComponent("dashboard.widgets", {
      id: "reboot",
      component: FirstComp,
    });

    const actions = getSlotComponents("instance.detail.actions");
    assert.equal(actions.length, 1);
    assert.equal(actions[0].component, ReplacementComp);
    assert.equal(actions[0].priority, 10);
    assert.deepEqual(actions[0].metadata, { version: 2 });

    const widgets = getSlotComponents("dashboard.widgets");
    assert.equal(widgets.length, 1);
    assert.equal(widgets[0].component, FirstComp);
  });

  it("unregisters items by ID and via the returned unregister callback", () => {
    const unregisterFirst = registerSlotComponent("login.providers", {
      id: "oidc",
      component: makeDummyComponent("OIDC"),
    });
    registerSlotComponent("login.providers", {
      id: "saml",
      component: makeDummyComponent("SAML"),
    });

    assert.equal(getSlotComponents("login.providers").length, 2);

    // Unregister using returned callback
    unregisterFirst();
    assert.deepEqual(
      getSlotComponents("login.providers").map((item) => item.id),
      ["saml"]
    );

    // Calling callback again is a safe no-op
    unregisterFirst();
    assert.equal(getSlotComponents("login.providers").length, 1);

    // Unregister by ID
    assert.equal(unregisterSlotComponent("login.providers", "saml"), true);
    assert.equal(unregisterSlotComponent("login.providers", "saml"), false);
    assert.equal(
      unregisterSlotComponent("login.providers", "non-existent"),
      false
    );
    assert.deepEqual(getSlotComponents("login.providers"), []);
  });

  it("clearSlotRegistry empties all registered slots", () => {
    registerSlotComponent("instance.detail.tabs", {
      id: "tab-1",
      component: makeDummyComponent("Tab1"),
    });
    registerSlotComponent("dashboard.widgets", {
      id: "widget-1",
      component: makeDummyComponent("Widget1"),
    });

    clearSlotRegistry();

    assert.deepEqual(getSlotComponents("instance.detail.tabs"), []);
    assert.deepEqual(getSlotComponents("dashboard.widgets"), []);
  });
});
