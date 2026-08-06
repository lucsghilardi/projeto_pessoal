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
  observacoes: string | null;
  posicao: number;
};

export type SaudeExercicioPayload = {
  treino_id?: number;
  nome: string;
  series?: number | null;
  repeticoes?: string | null;
  carga?: string | null;
  observacoes?: string | null;
};

export type SaudeTreino = {
  id: number;
  nome: string;
  posicao: number;
  exercicios: SaudeExercicio[];
};

export type SaudeTreinoResumo = {
  id: number;
  nome: string;
  posicao: number;
  exercicios_count: number;
};

export type SaudeTreinoSessao = {
  id: number;
  treino_id: number;
  /** "YYYY-MM-DD" */
  data: string;
  treino?: { id: number; nome: string };
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
  confianca: "alta" | "media" | "baixa" | null;
  origem: "whatsapp_foto" | "whatsapp_texto" | "manual";
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
};

export type SaudeNutricaoMetas = {
  completo: boolean;
  tmb: number | null;
  tdee: number | null;
  calorias: number | null;
  proteinas_g: number | null;
  deficit: number | null;
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
  peso: {
    ultimo: SaudePeso | null;
    /** Últimos 30 registros em ordem cronológica (sparkline). */
    recentes: Array<Pick<SaudePeso, "id" | "data" | "peso_kg">>;
    meta: SaudeMeta;
  };
};
