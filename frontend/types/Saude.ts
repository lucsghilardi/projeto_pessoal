export type SaudeSuplemento = {
  id: number;
  nome: string;
  marca: string | null;
  dose: string | null;
  /** "HH:MM:SS" vindo do backend — exibir com .slice(0, 5). */
  horario: string;
  instrucao: string | null;
  observacoes: string | null;
  ativo: boolean;
  posicao: number;
};

export type SaudeSuplementoDia = SaudeSuplemento & { tomado: boolean };

export type SaudeSuplementoPayload = {
  nome: string;
  marca?: string | null;
  dose?: string | null;
  /** "HH:MM" */
  horario: string;
  instrucao?: string | null;
  observacoes?: string | null;
  ativo?: boolean;
};

export type SaudeProgresso = { tomados: number; total: number };

export type SaudeStreak = { atual: number; recorde: number };

export type SaudeCheckinResult = {
  progresso: SaudeProgresso;
  streak: SaudeStreak;
};

export type SaudeExercicio = {
  id: number;
  treino_id: number;
  nome: string;
  series: number | null;
  repeticoes: string | null;
  carga: string | null;
  /** Blocos de cardio e o cardio final do A/B. */
  duracao_min: number | null;
  /** Livre: "Z2", "6,5 km/h". */
  intensidade: string | null;
  observacoes: string | null;
  posicao: number;
};

export type SaudeExercicioPayload = {
  treino_id?: number;
  nome: string;
  series?: number | null;
  repeticoes?: string | null;
  carga?: string | null;
  duracao_min?: number | null;
  intensidade?: string | null;
  observacoes?: string | null;
};

export type SaudeTreinoTipo = "musculacao" | "cardio";

export type SaudeTreino = {
  id: number;
  nome: string;
  tipo: SaudeTreinoTipo;
  posicao: number;
  exercicios: SaudeExercicio[];
};

export type SaudeTreinoResumo = {
  id: number;
  nome: string;
  tipo: SaudeTreinoTipo;
  posicao: number;
  exercicios_count: number;
};

export type SaudeTreinoSessao = {
  id: number;
  treino_id: number;
  /** "YYYY-MM-DD" */
  data: string;
  duracao_min: number | null;
  calorias: number | null;
  origem: "manual" | "garmin";
  treino?: { id: number; nome: string };
};

export type SaudeCardioModalidade =
  | "corrida_rua"
  | "esteira"
  | "bike"
  | "eliptico"
  | "caminhada"
  | "outro";

export type SaudeCardioSessao = {
  id: number;
  treino_id: number | null;
  /** "YYYY-MM-DD" */
  data: string;
  /** "HH:MM:SS" vindo do backend — exibir com .slice(0, 5). */
  horario: string | null;
  nome: string | null;
  modalidade: SaudeCardioModalidade;
  duracao_min: number;
  /** Decimal serializado como string (ex.: "3.15"). */
  distancia_km: string | null;
  calorias: number | null;
  fc_media: number | null;
  fc_maxima: number | null;
  intensidade: string | null;
  origem: "manual" | "garmin";
  garmin_activity_id: number | null;
  observacao: string | null;
  treino?: { id: number; nome: string } | null;
};

export type SaudeCardioSessaoPayload = {
  data: string;
  /** "HH:MM" */
  horario?: string | null;
  nome?: string | null;
  modalidade: SaudeCardioModalidade;
  duracao_min: number;
  distancia_km?: number | null;
  calorias?: number | null;
  fc_media?: number | null;
  fc_maxima?: number | null;
  intensidade?: string | null;
  treino_id?: number | null;
  observacao?: string | null;
};

export type SaudeGarminStatus = {
  configurado: boolean;
  status: {
    ok: boolean;
    autenticado: boolean;
    nome?: string;
    /** "nao_configurado" | "sidecar_indisponivel" | "tokens_invalidos" */
    erro?: string;
  };
};

export type SaudeGarminSync = {
  cardio: number;
  treinos: number;
  dias: number;
  ignorados: number;
};

/** Resposta do auto-sync: nunca é erro HTTP — falha vira `executado: false`. */
export type SaudeGarminAutoSync = {
  executado: boolean;
  motivo?: "nao_configurado" | "recente" | "erro";
  cardio?: number;
  treinos?: number;
  dias?: number;
  ignorados?: number;
};

export type SaudeCardioResumo = {
  de: string;
  ate: string;
  sessoes: number;
  minutos: number;
  distancia_km: number;
  calorias: number;
  /** Minutos por modalidade, só com as que aparecem no período. */
  por_modalidade: Partial<Record<SaudeCardioModalidade, number>>;
};

export type SaudePeso = {
  id: number;
  /** "YYYY-MM-DD" */
  data: string;
  /** Decimal serializado como string (ex.: "92.50"). */
  peso_kg: string;
  observacao: string | null;
};

export type SaudePesoPayload = {
  data: string;
  peso_kg: number;
  observacao?: string | null;
};

export type SaudeNivelAtividade =
  | "sedentario"
  | "leve"
  | "moderado"
  | "intenso"
  | "atleta";

export type SaudeMeta = {
  id: number;
  peso_meta_kg: string | null;
  data_alvo: string | null;
  altura_cm: number | null;
  sexo: "M" | "F" | null;
  /** "YYYY-MM-DD" */
  data_nascimento: string | null;
  nivel_atividade: SaudeNivelAtividade | null;
  /** Ligado: TDEE = TMB × fator_base + gasto real do dia (ignora nivel_atividade). */
  gasto_dinamico: boolean;
  /** Decimal serializado como string (ex.: "1.20"). Padrão 1,20. */
  fator_base: string | null;
  /** Override manual da meta calórica calculada (TDEE - déficit). */
  calorias_alvo: number | null;
  /** Override manual da meta de proteína (padrão: 1,8 g/kg). */
  proteinas_alvo_g: number | null;
} | null;

export type SaudeMetaPayload = {
  peso_meta_kg?: number | null;
  data_alvo?: string | null;
  altura_cm?: number | null;
  sexo?: "M" | "F" | null;
  data_nascimento?: string | null;
  nivel_atividade?: SaudeNivelAtividade | null;
  gasto_dinamico?: boolean;
  fator_base?: number | null;
  calorias_alvo?: number | null;
  proteinas_alvo_g?: number | null;
};

export type SaudeRefeicaoTipo =
  | "cafe_da_manha"
  | "almoco"
  | "jantar"
  | "lanche"
  | "outro";

export type SaudeRefeicaoItem = {
  nome: string;
  /** Porção em medida caseira, ex.: "4 colheres de sopa". */
  quantidade: string;
  calorias: number;
  proteinas_g?: number;
};

export type SaudeRefeicaoConfianca = "alta" | "media" | "baixa";

/**
 * `whatsapp_*`: veio da conversa consigo mesmo. `painel_ia`: estimada pela IA
 * no formulário e confirmada pelo usuário. `manual`: digitada por inteiro.
 */
export type SaudeRefeicaoOrigem =
  | "whatsapp_foto"
  | "whatsapp_texto"
  | "painel_ia"
  | "manual";

/** Estimativa da IA antes de virar refeição — o usuário ainda vai confirmar. */
export type SaudeRefeicaoAnalise = {
  /** false quando a foto/descrição não tem comida identificável. */
  e_comida: boolean;
  nome: string;
  tipo: SaudeRefeicaoTipo;
  itens: SaudeRefeicaoItem[];
  calorias: number;
  proteinas_g: number;
  carboidratos_g: number | null;
  gorduras_g: number | null;
  confianca: SaudeRefeicaoConfianca;
};

export type SaudeRefeicao = {
  id: number;
  /** "YYYY-MM-DD" */
  data: string;
  /** "HH:MM:SS" vindo do backend — exibir com .slice(0, 5). */
  horario: string;
  nome: string;
  tipo: SaudeRefeicaoTipo | null;
  /** Detalhamento por alimento estimado pela IA. */
  itens: SaudeRefeicaoItem[] | null;
  calorias: number;
  /** Decimal serializado como string (ex.: "45.0"). */
  proteinas_g: string;
  carboidratos_g: string | null;
  gorduras_g: string | null;
  confianca: SaudeRefeicaoConfianca | null;
  origem: SaudeRefeicaoOrigem;
  whatsapp_mensagem_id: number | null;
  foto_path: string | null;
  observacao: string | null;
};

export type SaudeRefeicaoPayload = {
  data: string;
  /** "HH:MM" */
  horario: string;
  nome: string;
  tipo?: SaudeRefeicaoTipo | null;
  calorias: number;
  proteinas_g?: number | null;
  carboidratos_g?: number | null;
  gorduras_g?: number | null;
  observacao?: string | null;
  /** Preenchidos ao confirmar uma estimativa da IA feita no painel. */
  origem?: Extract<SaudeRefeicaoOrigem, "manual" | "painel_ia">;
  confianca?: SaudeRefeicaoConfianca | null;
  itens?: SaudeRefeicaoItem[] | null;
};

export type SaudeNutricaoMetas = {
  completo: boolean;
  tmb: number | null;
  tdee: number | null;
  calorias: number | null;
  proteinas_g: number | null;
  deficit: number | null;
  /** Calorias gastas no dia, já somadas ao TDEE. 0 com gasto dinâmico desligado. */
  gasto_exercicio: number;
  gasto_dinamico: boolean;
};

export type SaudeNutricaoOverview = {
  data: string;
  perfil_completo: boolean;
  metas: SaudeNutricaoMetas;
  consumido: {
    calorias: number;
    proteinas_g: number;
    carboidratos_g: number;
    gorduras_g: number;
  };
  /** Pode ser negativo (meta estourada). Null sem meta definida. */
  restante: { calorias: number | null; proteinas_g: number | null };
  refeicoes: SaudeRefeicao[];
  /** Calorias gastas em exercício no dia (0 com gasto dinâmico desligado). */
  gasto_exercicio: number;
  /** Últimos 14 dias terminando no dia consultado; dias vazios zerados. */
  historico: Array<{ data: string; calorias: number; proteinas_g: number }>;
};

export type SaudeNutricaoProjecao = {
  peso_atual: number;
  peso_meta: number | null;
  data_alvo: string | null;
  /** kg/semana planejado (déficit da meta). Positivo = perdendo. */
  ritmo_plano_kg_semana: number | null;
  /** kg/semana observado nas pesagens de ~4 semanas. */
  ritmo_real_kg_semana: number | null;
  /** Quando chega na meta mantendo o ritmo real. */
  data_prevista_meta: string | null;
  pontos: Array<{ data: string; plano: number | null; ritmo_real: number | null }>;
} | null;

export type SaudeOverview = {
  data: string;
  /** Só os ativos, ordenados por horário. */
  suplementos: SaudeSuplementoDia[];
  progresso: SaudeProgresso;
  streak: SaudeStreak;
  treinos: SaudeTreinoResumo[];
  sessao_hoje: SaudeTreinoSessao | null;
  /** Últimos 30 dias, mais recente primeiro. */
  sessoes_recentes: SaudeTreinoSessao[];
  /** Pode ter mais de um: cardio do treino + cardio avulso no mesmo dia. */
  cardio_hoje: SaudeCardioSessao[];
  /** Últimos 30 dias, mais recente primeiro. */
  cardio_recentes: SaudeCardioSessao[];
  /** Totais dos últimos 7 dias terminando no dia consultado. */
  cardio_semana: Omit<SaudeCardioResumo, "de" | "ate">;
  peso: {
    ultimo: SaudePeso | null;
    /** Últimos 30 registros em ordem cronológica (sparkline). */
    recentes: Array<Pick<SaudePeso, "id" | "data" | "peso_kg">>;
    meta: SaudeMeta;
  };
};
