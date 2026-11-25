import { describe, it, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import {
  registerSlotComponent,
  getSlotComponents,
  clearSlotRegistry,
} from "../../../src/lib/registry/slotRegistry.ts";
import {
  registerVmTab,
  registerVmAction,
  getVmTabs,
  getVmActions,
} from "../../../src/lib/registry/vmRegistry.ts";

const makeDummyComponent = (label: string) => {
  const DummyComponent = () => null;
  DummyComponent.displayName = label;
  return DummyComponent;
};

describe("slotRegistryBridge", () => {
  const cleanups: Array<() => void> = [];

  beforeEach(() => {
    clearSlotRegistry();
  });

  afterEach(() => {
    while (cleanups.length > 0) {
      cleanups.pop()?.();
    }
    clearSlotRegistry();
  });

  it("bridges instance.detail.tabs slot components into getVmTabs sorted by order", () => {
    const BuiltinComp = makeDummyComponent("BuiltinOverview");
    const SlotTabComp = makeDummyComponent("SlotMetrics");
    const FallbackLabelComp = makeDummyComponent("FallbackLabel");

    cleanups.push(
      registerVmTab({
        id: "overview",
        label: "Overview",
        order: 10,
        component: BuiltinComp,
      })
    );

    registerSlotComponent("instance.detail.tabs", {
      id: "ext-metrics",
      priority: 5,
      component: SlotTabComp,
      metadata: { label: "Extension Metrics" },
    });

    registerSlotComponent("instance.detail.tabs", {
      id: "ext-logs",
      priority: 50,
      component: FallbackLabelComp,
    });

    const slotItems = getSlotComponents("instance.detail.tabs");
    assert.equal(slotItems.length, 2);

    const tabs = getVmTabs();
    assert.deepEqual(
      tabs.map((tab) => tab.id),
      ["ext-metrics", "overview", "ext-logs"]
    );
    assert.equal(tabs[0].label, "Extension Metrics");
    assert.equal(tabs[0].order, 5);
    assert.equal(tabs[0].component, SlotTabComp);

    assert.equal(tabs[1].label, "Overview");
    assert.equal(tabs[1].component, BuiltinComp);

    assert.equal(tabs[2].label, "ext-logs");
    assert.equal(tabs[2].order, 50);
    assert.equal(tabs[2].component, FallbackLabelComp);
  });

  it("bridges instance.detail.actions slot components into getVmActions sorted by order", () => {
    const ActionComp = makeDummyComponent("ActionComp");
    let clicked = false;

    cleanups.push(
      registerVmAction({
        id: "builtin-reboot",
        label: "Reboot",
        order: 20,
        onClick: () => {},
      })
    );

    registerSlotComponent("instance.detail.actions", {
      id: "ext-backup",
      priority: 10,
      component: ActionComp,
      metadata: {
        label: "Backup Now",
        variant: "primary",
        onClick: () => {
          clicked = true;
        },
      },
    });

    const actions = getVmActions();
    assert.deepEqual(
      actions.map((action) => action.id),
      ["ext-backup", "builtin-reboot"]
    );
    assert.equal(actions[0].label, "Backup Now");
    assert.equal(actions[0].order, 10);
    assert.equal(actions[0].variant, "primary");

    actions[0].onClick({ vm: {} as never, user: null, isAdmin: false });
    assert.equal(clicked, true);
  });

  it("retrieves dashboard.widgets slot components with their component sorted by priority", () => {
    const PrimaryWidget = makeDummyComponent("PrimaryWidget");
    const SecondaryWidget = makeDummyComponent("SecondaryWidget");

    registerSlotComponent("dashboard.widgets", {
      id: "secondary-widget",
      priority: 200,
      component: SecondaryWidget,
    });
    registerSlotComponent("dashboard.widgets", {
      id: "primary-widget",
      priority: 10,
      component: PrimaryWidget,
    });

    const widgets = getSlotComponents("dashboard.widgets");
    assert.equal(widgets.length, 2);
    assert.equal(widgets[0].id, "primary-widget");
    assert.equal(widgets[0].component, PrimaryWidget);
    assert.equal(widgets[1].id, "secondary-widget");
    assert.equal(widgets[1].component, SecondaryWidget);
  });
});
