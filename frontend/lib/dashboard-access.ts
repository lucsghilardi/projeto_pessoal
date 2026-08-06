import type { LucideIcon } from "lucide-react";
import { ArrowDownCircle, ArrowUpCircle, Banknote, BarChart3, Boxes, CircleDollarSign, CreditCard, Dumbbell, Flame, Footprints, Gem, Handshake, HeartPulse, KanbanSquare, Landmark, LayoutDashboard, Lightbulb, MessageCircle, MessagesSquare, Pill, Scale, ScanLine, ScrollText, Tags, Target, TrendingUp, Trophy, Users, Wallet } from "lucide-react";

import { UserRole } from "@/types/User";

type DashboardRouteRule = {
  path: string;
  roles: UserRole[];
  exact?: boolean;
};

export type DashboardNavLink = {
  title: string;
  url: string;
  roles: UserRole[];
  icon: LucideIcon;
};

export type DashboardNavGroup = {
  label: string;
  icon: LucideIcon;
  items: DashboardNavLink[];
};

export type DashboardNavEntry =
  | ({ kind: "link" } & DashboardNavLink)
  | ({ kind: "group" } & DashboardNavGroup);

const ALL_ROLES: UserRole[] = ["admin", "editor", "viewer"];

/** Tela inicial do painel: para onde /dashboard leva depois do login. */
export const DASHBOARD_HOME_ROUTE = "/dashboard/tarefas/relatorios";

const dashboardRouteRules: DashboardRouteRule[] = [
  { path: "/dashboard/tarefas", roles: ALL_ROLES },
  { path: "/dashboard/investments", roles: ALL_ROLES },
  { path: "/dashboard/assets", roles: ALL_ROLES },
  { path: "/dashboard/finance/credit-cards", roles: ALL_ROLES },
  { path: "/dashboard/finance/plano-1-milhao", roles: ALL_ROLES },
  { path: "/dashboard/finance/acertos", roles: ALL_ROLES },
  { path: "/dashboard/finance", roles: ALL_ROLES },
  { path: "/dashboard/consorcios", roles: ALL_ROLES },
  { path: "/dashboard/saude", roles: ALL_ROLES },
  { path: "/dashboard/whatsapp", roles: ["admin"] },
  { path: "/dashboard/users", roles: ["admin"] },
  { path: "/dashboard", roles: ALL_ROLES, exact: true },
];

export const dashboardNav: DashboardNavEntry[] = [
  {
    kind: "group",
    label: "Tarefas",
    icon: KanbanSquare,
    items: [
      { title: "Visão geral", url: "/dashboard/tarefas", roles: ALL_ROLES, icon: KanbanSquare },
      { title: "Relatórios", url: "/dashboard/tarefas/relatorios", roles: ALL_ROLES, icon: BarChart3 },
      { title: "Conquistas", url: "/dashboard/tarefas/conquistas", roles: ALL_ROLES, icon: Trophy },
    ],
  },
  {
    kind: "group",
    label: "Financeiro",
    icon: CircleDollarSign,
    items: [
      { title: "Painel", url: "/dashboard/finance", roles: ALL_ROLES, icon: LayoutDashboard },
      { title: "Relatórios", url: "/dashboard/finance/reports", roles: ALL_ROLES, icon: BarChart3 },
      { title: "Plano 1 Milhão", url: "/dashboard/finance/plano-1-milhao", roles: ALL_ROLES, icon: Target },
      { title: "Lançar via IA", url: "/dashboard/finance/lancamento-ia", roles: ALL_ROLES, icon: ScanLine },
      { title: "Contas a pagar", url: "/dashboard/finance/payables", roles: ALL_ROLES, icon: ArrowDownCircle },
      { title: "Contas a receber", url: "/dashboard/finance/receivables", roles: ALL_ROLES, icon: ArrowUpCircle },
      { title: "Acertos", url: "/dashboard/finance/acertos", roles: ALL_ROLES, icon: Handshake },
      { title: "Contas (saldos)", url: "/dashboard/finance/accounts", roles: ALL_ROLES, icon: Banknote },
      { title: "Categorias", url: "/dashboard/finance/categories", roles: ALL_ROLES, icon: Tags },
    ],
  },
  {
    kind: "group",
    label: "Investimentos",
    icon: TrendingUp,
    items: [
      { title: "Carteira", url: "/dashboard/investments", roles: ALL_ROLES, icon: Wallet },
      { title: "Propósitos", url: "/dashboard/investments/purposes", roles: ALL_ROLES, icon: Target },
      { title: "Instituições", url: "/dashboard/investments/institutions", roles: ALL_ROLES, icon: Landmark },
    ],
  },
  {
    kind: "group",
    label: "Saúde",
    icon: HeartPulse,
    items: [
      { title: "Check-in do dia", url: "/dashboard/saude", roles: ALL_ROLES, icon: Pill },
      { title: "Calorias", url: "/dashboard/saude/calorias", roles: ALL_ROLES, icon: Flame },
      { title: "Treinos", url: "/dashboard/saude/treinos", roles: ALL_ROLES, icon: Dumbbell },
      { title: "Cardio", url: "/dashboard/saude/cardio", roles: ALL_ROLES, icon: Footprints },
      { title: "Peso", url: "/dashboard/saude/peso", roles: ALL_ROLES, icon: Scale },
    ],
  },
  {
    kind: "group",
    label: "WhatsApp",
    icon: MessageCircle,
    items: [
      { title: "Visão geral", url: "/dashboard/whatsapp", roles: ["admin"], icon: MessageCircle },
      { title: "Conversas", url: "/dashboard/whatsapp/conversas", roles: ["admin"], icon: MessagesSquare },
      { title: "Relatórios", url: "/dashboard/whatsapp/relatorios", roles: ["admin"], icon: ScrollText },
      { title: "Sugestões", url: "/dashboard/whatsapp/sugestoes", roles: ["admin"], icon: Lightbulb },
    ],
  },
  { kind: "link", title: "Patrimônios", url: "/dashboard/assets", roles: ALL_ROLES, icon: Gem },
  { kind: "link", title: "Cartões de crédito", url: "/dashboard/finance/credit-cards", roles: ALL_ROLES, icon: CreditCard },
  { kind: "link", title: "Consórcios", url: "/dashboard/consorcios", roles: ALL_ROLES, icon: Boxes },
  { kind: "link", title: "Usuários", url: "/dashboard/users", roles: ["admin"], icon: Users },
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

export function getDashboardNavForRole(role: UserRole): DashboardNavEntry[] {
  return dashboardNav.flatMap((entry): DashboardNavEntry[] => {
    if (entry.kind === "link") {
      return entry.roles.includes(role) ? [entry] : [];
    }

    const items = entry.items.filter((item) => item.roles.includes(role));
    return items.length > 0 ? [{ ...entry, items }] : [];
  });
}

export function isDashboardItemActive(pathname: string, url: string) {
  if (url === "/dashboard") {
    return pathname === url;
  }

  // "Carteira" (/dashboard/investments) não deve ficar ativa em /investments/purposes.
  // "Visão geral" das tarefas idem: não destaca nos boards de projeto (/tarefas/{id}).
  if (
    url === "/dashboard/investments" ||
    url === "/dashboard/finance" ||
    url === "/dashboard/tarefas" ||
    url === "/dashboard/whatsapp" ||
    url === "/dashboard/saude"
  ) {
    return pathname === url;
  }

  return pathname === url || pathname.startsWith(`${url}/`);
}

export function getDashboardFallbackRoute(role: UserRole) {
  // Mesma tela do login, desde que o papel alcance — a guarda evita mandar
  // alguém para uma rota barrada e criar um laço de redirecionamento.
  if (canAccessDashboardRoute(role, DASHBOARD_HOME_ROUTE)) {
    return DASHBOARD_HOME_ROUTE;
  }

  const first = getDashboardNavForRole(role)[0];

  if (!first) {
    return "/dashboard";
  }

  return first.kind === "link" ? first.url : (first.items[0]?.url ?? "/dashboard");
}
