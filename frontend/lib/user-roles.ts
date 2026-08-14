import { UserRole } from "@/types/User";

/**
 * Cada conta é um painel pessoal independente: o papel não muda o que a pessoa
 * pode fazer com os próprios dados, só quem administra usuários. Por isso
 * `editor` e `viewer` aparecem como a mesma coisa — `viewer` é o default antigo
 * da coluna e ainda existe em contas criadas antes.
 */
export const ROLE_LABELS: Record<UserRole, string> = {
  admin: "Administrador",
  editor: "Usuário",
  viewer: "Usuário",
};

export const ROLE_DESCRIPTIONS: Record<UserRole, string> = {
  admin: "Usa o painel e também cria, edita e desativa contas.",
  editor: "Usa o painel com os próprios dados. Não gerencia usuários.",
  viewer: "Usa o painel com os próprios dados. Não gerencia usuários.",
};

export const ROLE_ORDER: Record<UserRole, number> = {
  admin: 0,
  editor: 1,
  viewer: 2,
};

/** Papéis oferecidos ao criar/editar. `viewer` fica só como valor legado. */
export const ROLE_OPTIONS: UserRole[] = ["admin", "editor"];
