import { redirect } from "next/navigation";

export default function DashboardHome() {
  // O painel atual contém apenas Configurações › Usuários.
  redirect("/dashboard/users");
}
