import { redirect } from "next/navigation";

export default function DashboardHome() {
  // A carteira de investimentos é a tela inicial do painel.
  redirect("/dashboard/investments");
}
