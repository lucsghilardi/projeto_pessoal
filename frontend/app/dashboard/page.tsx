import { redirect } from "next/navigation";

import { DASHBOARD_HOME_ROUTE } from "@/lib/dashboard-access";

export default function DashboardHome() {
  // O relatório de tarefas é a tela inicial do painel.
  redirect(DASHBOARD_HOME_ROUTE);
}
