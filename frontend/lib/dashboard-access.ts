import type { LucideIcon } from "lucide-react";
import { ArrowDownCircle, ArrowUpCircle, Banknote, CreditCard, Gem, Landmark, LayoutDashboard, ScanLine, Tags, Target, Users, Wallet } from "lucide-react";

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
  { path: "/dashboard/investments", roles: ALL_ROLES },
  { path: "/dashboard/assets", roles: ALL_ROLES },
  { path: "/dashboard/finance/credit-cards", roles: ALL_ROLES },
  { path: "/dashboard/finance", roles: ALL_ROLES },
  { path: "/dashboard/users", roles: ["admin"] },
  { path: "/dashboard", roles: ALL_ROLES, exact: true },
];

export const dashboardNavSections: DashboardNavSection[] = [
  {
    label: "Investimentos",
    items: [
      { title: "Carteira", url: "/dashboard/investments", roles: ALL_ROLES, icon: Wallet },
      { title: "Propósitos", url: "/dashboard/investments/purposes", roles: ALL_ROLES, icon: Target },
      { title: "Instituições", url: "/dashboard/investments/institutions", roles: ALL_ROLES, icon: Landmark },
    ],
  },
  {
    label: "Patrimônio",
    items: [
      { title: "Patrimônios", url: "/dashboard/assets", roles: ALL_ROLES, icon: Gem },
    ],
  },
  {
    label: "Financeiro",
    items: [
      { title: "Painel", url: "/dashboard/finance", roles: ALL_ROLES, icon: LayoutDashboard },
      { title: "Lançar via IA", url: "/dashboard/finance/lancamento-ia", roles: ALL_ROLES, icon: ScanLine },
      { title: "Contas a pagar", url: "/dashboard/finance/payables", roles: ALL_ROLES, icon: ArrowDownCircle },
      { title: "Contas a receber", url: "/dashboard/finance/receivables", roles: ALL_ROLES, icon: ArrowUpCircle },
      { title: "Contas (saldos)", url: "/dashboard/finance/accounts", roles: ALL_ROLES, icon: Banknote },
      { title: "Categorias", url: "/dashboard/finance/categories", roles: ALL_ROLES, icon: Tags },
    ],
  },
  {
    label: "Cartões",
    items: [
      { title: "Cartões de crédito", url: "/dashboard/finance/credit-cards", roles: ALL_ROLES, icon: CreditCard },
    ],
  },
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

  // "Carteira" (/dashboard/investments) não deve ficar ativa em /investments/purposes.
  if (url === "/dashboard/investments" || url === "/dashboard/finance") {
    return pathname === url;
  }

  return pathname === url || pathname.startsWith(`${url}/`);
}

export function getDashboardFallbackRoute(role: UserRole) {
  const firstSection = getDashboardNavSectionsForRole(role)[0];
  return firstSection?.items[0]?.url ?? "/dashboard";
}
