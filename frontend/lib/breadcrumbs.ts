/**
 * Rótulo de cada segmento de rota. Segmento não mapeado cai no fallback do
 * layout (primeira letra maiúscula) — ver `rotuloBreadcrumb`.
 */
export const breadcrumbMap: Record<string, string> = {
  dashboard: "Dashboard",
  tarefas: "Tarefas",
  conquistas: "Conquistas",
  relatorios: "Relatórios",
  investments: "Investimentos",
  assets: "Patrimônios",
  purposes: "Propósitos",
  institutions: "Instituições",
  users: "Usuários",
  perfil: "Perfil",
  finance: "Financeiro",
  reports: "Relatórios",
  "plano-1-milhao": "Plano 1 Milhão",
  "lancamento-ia": "Lançar via IA",
  payables: "Contas a pagar",
  receivables: "Contas a receber",
  acertos: "Acertos",
  accounts: "Contas",
  categories: "Categorias",
  "credit-cards": "Cartões de crédito",
  consorcios: "Consórcios",
  whatsapp: "WhatsApp",
  conversas: "Conversas",
  sugestoes: "Sugestões",
  saude: "Saúde",
  calorias: "Calorias",
  treinos: "Treinos",
  cardio: "Cardio",
  peso: "Peso",
};

/**
 * Rótulo de um segmento da URL.
 *
 * Rotas de detalhe terminam num id numérico (/saude/cardio/42), que sem
 * tratamento apareceria cru na trilha. "Detalhe" diz mais do que "42".
 */
export function rotuloBreadcrumb(segment: string): string {
  if (breadcrumbMap[segment]) return breadcrumbMap[segment];
  if (/^\d+$/.test(segment)) return "Detalhe";

  return segment.charAt(0).toUpperCase() + segment.slice(1);
}
