import { describe, it, beforeEach } from "node:test";
import assert from "node:assert/strict";
import {
  registerNavigationItem,
  getNavigationItems,
  unregisterNavigationItem,
  clearNavigationRegistry,
  subscribeNavigationRegistry,
  type NavigationItem,
} from "../../../src/lib/registry/navigationRegistry.ts";

const makeDummyIcon = (name: string) => {
  const DummyIcon = ({ className }: { className?: string }) => {
    void className;
    return null;
  };
  DummyIcon.displayName = name;
  return DummyIcon;
};

describe("navigationRegistry", () => {
  beforeEach(() => {
    clearNavigationRegistry();
  });

  it("registers a navigation item and retrieves it", () => {
    const Icon = makeDummyIcon("BillingIcon");
    const item: NavigationItem = {
      id: "billing",
      label: "Billing",
      href: "/extensions/billing",
      icon: Icon,
      badge: "New",
      target: "_self",
    };

    registerNavigationItem(item);

    const items = getNavigationItems();
    assert.equal(items.length, 1);
    assert.equal(items[0].id, "billing");
    assert.equal(items[0].label, "Billing");
    assert.equal(items[0].href, "/extensions/billing");
    assert.equal(items[0].icon, Icon);
    assert.equal(items[0].badge, "New");
    assert.equal(items[0].target, "_self");
  });

  it("returns items sorted ascending by priority with default priority 100", () => {
    registerNavigationItem({
      id: "default-priority",
      label: "Default",
      href: "/default",
    });
    registerNavigationItem({
      id: "low-priority",
      label: "Low",
      href: "/low",
      priority: 200,
    });
    registerNavigationItem({
      id: "mid-priority",
      label: "Mid",
      href: "/mid",
      priority: 50,
    });
    registerNavigationItem({
      id: "high-priority",
      label: "High",
      href: "/high",
      priority: 10,
    });

    const items = getNavigationItems();
    assert.deepEqual(
      items.map((item) => item.id),
      ["high-priority", "mid-priority", "default-priority", "low-priority"]
    );
  });

  it("filters items by requiredRole based on userRoles", () => {
    registerNavigationItem({
      id: "public-ext",
      label: "Support",
      href: "/support",
    });
    registerNavigationItem({
      id: "admin-ext",
      label: "License Manager",
      href: "/admin/license",
      requiredRole: "admin",
    });

    assert.deepEqual(
      getNavigationItems().map((item) => item.id),
      ["public-ext"]
    );
    assert.deepEqual(
      getNavigationItems(["user"]).map((item) => item.id),
      ["public-ext"]
    );
    assert.deepEqual(
      getNavigationItems(["user", "admin"]).map((item) => item.id),
      ["public-ext", "admin-ext"]
    );
  });

  it("replaces an existing item when registering a duplicate id", () => {
    registerNavigationItem({
      id: "metrics",
      label: "Old Metrics",
      href: "/old-metrics",
      priority: 50,
    });
    registerNavigationItem({
      id: "metrics",
      label: "New Metrics",
      href: "/new-metrics",
      priority: 10,
      badge: 3,
    });

    const items = getNavigationItems();
    assert.equal(items.length, 1);
    assert.equal(items[0].label, "New Metrics");
    assert.equal(items[0].href, "/new-metrics");
    assert.equal(items[0].priority, 10);
    assert.equal(items[0].badge, 3);
  });

  it("removes items via the returned unregister callback and unregisterNavigationItem", () => {
    const unregisterFirst = registerNavigationItem({
      id: "first",
      label: "First",
      href: "/first",
    });
    registerNavigationItem({
      id: "second",
      label: "Second",
      href: "/second",
    });

    assert.equal(getNavigationItems().length, 2);

    unregisterFirst();
    assert.deepEqual(
      getNavigationItems().map((item) => item.id),
      ["second"]
    );

    // Calling callback again is a safe no-op
    unregisterFirst();
    assert.equal(getNavigationItems().length, 1);

    assert.equal(unregisterNavigationItem("second"), true);
    assert.equal(unregisterNavigationItem("second"), false);
    assert.equal(unregisterNavigationItem("non-existent"), false);
    assert.deepEqual(getNavigationItems(), []);
  });

  it("clearNavigationRegistry empties the registry", () => {
    registerNavigationItem({
      id: "ext-1",
      label: "Ext 1",
      href: "/ext-1",
    });
    registerNavigationItem({
      id: "ext-2",
      label: "Ext 2",
      href: "/ext-2",
    });

    clearNavigationRegistry();

    assert.deepEqual(getNavigationItems(), []);
  });

  it("notifies subscribers on mutations and stabilizes snapshot references", () => {
    let callCount = 0;
    const unsubscribe = subscribeNavigationRegistry(() => {
      callCount += 1;
    });

    const initialA = getNavigationItems(["admin"]);
    const initialB = getNavigationItems(["admin"]);
    assert.equal(initialA, initialB);

    const unregister = registerNavigationItem({
      id: "sub-test",
      label: "Sub Test",
      href: "/sub-test",
    });
    assert.equal(callCount, 1);

    const afterRegister = getNavigationItems(["admin"]);
    assert.notEqual(afterRegister, initialA);
    assert.equal(afterRegister, getNavigationItems(["admin"]));

    unregister();
    assert.equal(callCount, 2);

    // Unsubscribing stops further notifications
    unsubscribe();
    registerNavigationItem({
      id: "sub-test-2",
      label: "Sub Test 2",
      href: "/sub-test-2",
    });
    assert.equal(callCount, 2);
  });
});
