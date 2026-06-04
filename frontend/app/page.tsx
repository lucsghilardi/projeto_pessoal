import { redirect } from "next/navigation";

export default function Home() {
  // Sem site público: a raiz leva ao painel. O middleware redireciona para
  // /login quando não há sessão, ou para /dashboard/users quando autenticado.
  redirect("/dashboard");
}
