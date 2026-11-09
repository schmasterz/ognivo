"use client";

import InstancesPage from "./instances/page";
import {
  TabType,
  resolveTabFromPathname,
} from "@/components/layout/useDashboardNavigation";

export { type TabType, resolveTabFromPathname };

export default function DashboardRootPage() {
  return <InstancesPage />;
}
