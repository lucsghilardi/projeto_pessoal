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
  sono: number;
  ignorados: number;
};

/** Resposta do auto-sync: nunca é erro HTTP — falha vira `executado: false`. */
export type SaudeGarminAutoSync = {
  executado: boolean;
  motivo?: "nao_configurado" | "recente" | "erro";
  cardio?: number;
  treinos?: number;
  dias?: number;
  sono?: number;
  ignorados?: number;
};

/** Uma volta do relógio. Distância e duração vêm com a precisão original. */
export type SaudeCardioSplit = {
  numero: number;
  distancia_m: number;
  duracao_seg: number;
  pace_seg_km: number;
  fc_media: number | null;
  fc_maxima: number | null;
  cadencia: number | null;
  elevacao_ganho_m: number | null;
};

export type SaudeCardioZonaFc = {
  zona: number;
  segundos: number;
  fc_minima: number | null;
};

/**
 * Detalhe buscado no Garmin sob demanda. `null` na resposta significa que
 * ninguém abriu esta corrida ainda — o front dispara a busca uma vez.
 */
export type SaudeCardioDetalhe = {
  id: number;
  cardio_sessao_id: number;
  garmin_activity_id: number;
  splits: SaudeCardioSplit[] | null;
  zonas_fc: SaudeCardioZonaFc[] | null;
  duracao_seg: number | null;
  tempo_movimento_seg: number | null;
  distancia_m: number | null;
  passos: number | null;
  cadencia_media: number | null;
  passada_media_cm: number | null;
  potencia_media: number | null;
  fc_minima: number | null;
  elevacao_ganho_m: number | null;
  elevacao_perda_m: number | null;
  /** Decimais serializados como string. */
  training_effect_aerobico: string | null;
  training_effect_anaerobico: string | null;
  vo2max: string | null;
  sincronizado_em: string | null;
};

/** Duração e distância exatas quando `fonte` é "garmin"; arredondadas se "sessao". */
export type SaudeCardioMetricas = {
  duracao_seg: number | null;
  distancia_km: number | null;
  pace_seg_km: number | null;
  fonte: "garmin" | "sessao";
};

export type SaudeCardioPacingVeredito =
  | "negative_split"
  | "even"
  | "positive_split"
  | "irregular"
  | "indefinido";

/** Leitura dos splits calculada no backend (a IA não recalcula nada disso). */
export type SaudeCardioAnaliseSplits = {
  voltas: SaudeCardioSplit[];
  pace_medio_seg_km: number;
  primeira_metade_seg_km: number | null;
  segunda_metade_seg_km: number | null;
  variacao_pct: number | null;
  veredito: SaudeCardioPacingVeredito;
  deriva_fc_bpm: number | null;
  desvio_pace_seg: number;
};

export type SaudeCardioPercentil = {
  percentil: number;
  classificacao: string;
  mediana: number;
};

/**
 * Comparativo com as normas da faixa etária.
 *
 * `vo2max_relogio` é a estimativa do Garmin (por FC e ritmo) e
 * `vo2max_desempenho` é o VDOT do tempo real desta corrida. Divergir é comum e
 * significativo — a tela mostra os dois em vez de escolher um.
 */
export type SaudeCardioBenchmarks = {
  idade: number | null;
  sexo: "M" | "F" | null;
  peso_kg: number | null;
  fc_maxima: number | null;
  /** true quando a FC máxima veio da fórmula de Tanaka, não de um teste. */
  fc_maxima_estimada: boolean;
  fc_limiar: number | null;
  /**
   * true quando a FC média passou de 88% da máxima — esforço de prova.
   * Sendo false, `vo2max_desempenho` e `age_grade` descrevem esta corrida, não
   * o condicionamento: um treino leve derruba o VDOT de qualquer atleta.
   */
  esforco_maximo: boolean;
  esforco_fracao_fcmax: number | null;
  vo2max_relogio: number | null;
  vo2max_desempenho: number | null;
  percentil: SaudeCardioPercentil | null;
  /** Só vem preenchido quando `esforco_maximo` é true. */
  percentil_desempenho: SaudeCardioPercentil | null;
  /** Curva da faixa etária: { "5": 27.2, "50": 42.4, ... } */
  faixa_vo2max: Record<string, number> | null;
  idade_fitness: number | null;
  age_grade: {
    percentual: number;
    distancia_referencia: number;
    tempo_normalizado: number;
    tempo_padrao: number;
  } | null;
  projecoes: {
    /** "garmin" = previsão do relógio; "corrida" = derivada desta sessão. */
    fonte: "garmin" | "corrida";
    /** Segundos por prova: { "5k": 1553, "10k": 3387, ... } */
    tempos: Record<string, number>;
  } | null;
  projecao_peso: {
    peso_alvo_kg: number;
    vo2max_projetado: number;
    pace_atual_seg_km: number;
    pace_projetado_seg_km: number;
    ganho_seg_km: number;
  } | null;
};

export type SaudeCardioEvolucao = {
  id: number;
  data: string;
  distancia_km: number | null;
  duracao_min: number;
  pace_seg_km: number | null;
  fc_media: number | null;
  /** Batimentos gastos por km — melhora antes do pace quando a forma sobe. */
  batimentos_por_km: number | null;
  atual: boolean;
};

export type SaudeCardioAnaliseIA = {
  id: number;
  cardio_sessao_id: number;
  modelo: string;
  gerado_em: string;
  dados: {
    nota_geral: number;
    resumo: string;
    pacing: { veredito: SaudeCardioPacingVeredito; comentario: string };
    esforco: { zona_predominante: string; deriva_cardiaca: string; comentario: string };
    pontos_fortes: string[];
    pontos_de_atencao: string[];
    comparativo: string;
    nutricao: { pre_treino: string; durante: string; pos_treino: string; hidratacao: string };
    proximo_treino: { tipo: string; descricao: string; quando: string };
    meta_curto_prazo: string;
  };
};

/** Payload de GET /saude/cardio/{id}. */
export type SaudeCardioDetalhePagina = {
  sessao: SaudeCardioSessao;
  detalhe: SaudeCardioDetalhe | null;
  metricas: SaudeCardioMetricas;
  splits: SaudeCardioAnaliseSplits | null;
  benchmarks: SaudeCardioBenchmarks;
  evolucao: SaudeCardioEvolucao[];
  analise: SaudeCardioAnaliseIA | null;
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

/** "POOR" | "FAIR" | "GOOD" | "EXCELLENT" — vocabulário do próprio Garmin. */
export type SaudeSonoQualificador = "POOR" | "FAIR" | "GOOD" | "EXCELLENT";

/**
 * Uma noite. `data` é o dia em que se ACORDOU (convenção do Garmin): a noite
 * de 07 para 08 é o registro do dia 08.
 */
export type SaudeSono = {
  id: number;
  /** "YYYY-MM-DD" */
  data: string;
  /** Sono efetivo, já sem os despertares. */
  duracao_min: number;
  profundo_min: number | null;
  leve_min: number | null;
  rem_min: number | null;
  acordado_min: number | null;
  cochilo_min: number | null;
  despertares: number | null;
  /** 0-100, calculado pelo Garmin. Null em noite lançada à mão. */
  score: number | null;
  score_qualificador: SaudeSonoQualificador | null;
  /** "HH:MM:SS" vindo do backend — exibir com .slice(0, 5). */
  inicio: string | null;
  fim: string | null;
  /** Decimais serializados como string (ex.: "69.0"). */
  hrv_medio: string | null;
  estresse_medio: string | null;
  origem: "manual" | "garmin";
  observacao: string | null;
};

export type SaudeSonoPayload = {
  data: string;
  duracao_min: number;
  profundo_min?: number | null;
  leve_min?: number | null;
  rem_min?: number | null;
  acordado_min?: number | null;
  cochilo_min?: number | null;
  despertares?: number | null;
  score?: number | null;
  /** "HH:MM" */
  inicio?: string | null;
  fim?: string | null;
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
  /** Meta de sono por noite, em minutos (450 = 7h30). */
  sono_meta_min: number | null;
  sexo: "M" | "F" | null;
  /** "YYYY-MM-DD" */
  data_nascimento: string | null;
  nivel_atividade: SaudeNivelAtividade | null;
  /** FC máxima medida em teste. Vazio faz o cardio estimar por idade (Tanaka). */
  fc_maxima: number | null;
  /** Espelho do que o Garmin calcula; o sync sobrescreve. Decimal como string. */
  vo2max: string | null;
  fc_limiar: number | null;
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
  sono_meta_min?: number | null;
  sexo?: "M" | "F" | null;
  data_nascimento?: string | null;
  nivel_atividade?: SaudeNivelAtividade | null;
  fc_maxima?: number | null;
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
  /**
   * Últimos 14 dias terminando no dia consultado; dias vazios zerados.
   * `sono_min` é null (não zero) quando não houve noite medida.
   */
  historico: Array<{
    data: string;
    calorias: number;
    proteinas_g: number;
    sono_min: number | null;
  }>;
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
  sono: {
    /** A noite que terminou no dia consultado. */
    noite: SaudeSono | null;
    /** Últimos 30 dias em ordem cronológica (sparkline). */
    recentes: Array<Pick<SaudeSono, "id" | "data" | "duracao_min" | "score">>;
    meta_min: number | null;
  };
};
