import { redirect } from "next/navigation";

export default function Home() {
  // Sem site público: a raiz leva ao painel. O middleware manda para /login
  // quando não há sessão; com sessão, /dashboard segue para a tela inicial.
  redirect("/dashboard");
}
