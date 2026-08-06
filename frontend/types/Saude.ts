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

export type SaudeMeta = {
  id: number;
  peso_meta_kg: string | null;
  data_alvo: string | null;
  altura_cm: number | null;
} | null;

export type SaudeMetaPayload = {
  peso_meta_kg?: number | null;
  data_alvo?: string | null;
  altura_cm?: number | null;
};

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
