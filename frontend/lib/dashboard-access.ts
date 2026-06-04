import type { LucideIcon } from "lucide-react";
import { Users } from "lucide-react";

import { UserRole } from "@/types/User";

type DashboardRouteRule = {
  path: string;
  roles: UserRole[];
  exact?: boolean;
};

export type DashboardNavSection = {
  label: string;
  items: Array<{
    title: string;
    url: string;
    roles: UserRole[];
    icon: LucideIcon;
  }>;
};

const ALL_ROLES: UserRole[] = ["admin", "editor", "viewer"];

const dashboardRouteRules: DashboardRouteRule[] = [
  { path: "/dashboard/users", roles: ["admin"] },
  { path: "/dashboard", roles: ALL_ROLES, exact: true },
];

export const dashboardNavSections: DashboardNavSection[] = [
  {
    label: "Configurações",
    items: [
      { title: "Usuários", url: "/dashboard/users", roles: ["admin"], icon: Users },
    ],
  },
];

export function canAccessDashboardRoute(role: UserRole, pathname: string) {
  const matchedRule = dashboardRouteRules.find((rule) => {
    if (rule.exact) {
      return pathname === rule.path;
    }

    return pathname === rule.path || pathname.startsWith(`${rule.path}/`);
  });

  if (!matchedRule) {
    return false;
  }

  return matchedRule.roles.includes(role);
}

export function getDashboardNavSectionsForRole(role: UserRole) {
  return dashboardNavSections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.roles.includes(role)),
    }))
    .filter((section) => section.items.length > 0);
}

export function isDashboardItemActive(pathname: string, url: string) {
  if (url === "/dashboard") {
    return pathname === url;
  }

  return pathname === url || pathname.startsWith(`${url}/`);
}

export function getDashboardFallbackRoute(role: UserRole) {
  const firstSection = getDashboardNavSectionsForRole(role)[0];
  return firstSection?.items[0]?.url ?? "/dashboard";
}
