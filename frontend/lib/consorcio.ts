// Projeção financeira de um consórcio com parcela reduzida (50% até a contemplação)
// e reajuste anual (IPCA). Funções puras — espelham o padrão de helpers do topo de
// app/dashboard/finance/plano-1-milhao/page.tsx.
//
// Premissa central: a redução é ADIAMENTO de fluxo de caixa, não desconto. O total
// pago é ~constante (parcela cheia × prazo = ~118% do crédito), independente de quando
// contempla. A redução só baixa a parcela agora; após a contemplação a parcela "pula"
// para a cheia + a diferença acumulada diluída no prazo restante.

export interface ProjecaoInput {
  credito: number;
  prazo: number; // total de parcelas (meses)
  parcelaReduzida: number; // parcela atual (reduzida)
  reducaoPct: number; // % de redução até a contemplação (ex.: 50)
  contemplacaoMes: number; // mês assumido de contemplação (1..prazo)
  ipcaAnualPct: number; // reajuste anual esperado (% a.a.)
  parcelasPagas: number; // parcelas já pagas
}

export interface ProjecaoRow {
  mes: number;
  ano: number;
  parcela: number;
  pagoAcumulado: number;
  saldoDevedor: number;
  fase: "reduzida" | "cheia";
}

export interface ProjecaoTotais {
  parcelaCheia: number;
  totalNominal: number; // sem reajuste
  totalEstimado: number; // com reajuste
  jaPago: number;
  falta: number;
  parcelaHoje: number;
  parcelaPosContemplacao: number;
}

export interface Projecao {
  rows: ProjecaoRow[];
  totais: ProjecaoTotais;
}

/** Parcela "cheia" (sem redução) a partir da reduzida e do % de redução. */
export function parcelaCheia(parcelaReduzida: number, reducaoPct: number): number {
  const fator = 1 - reducaoPct / 100;
  if (fator <= 0) return parcelaReduzida; // proteção contra divisão por zero
  return parcelaReduzida / fator;
}

/** Constrói a projeção mês a mês, garantindo que a soma nominal = parcela cheia × prazo. */
export function buildProjecao(input: ProjecaoInput): Projecao {
  const prazo = Math.max(1, Math.round(input.prazo || 1));
  const contempla = Math.min(Math.max(1, Math.round(input.contemplacaoMes || 1)), prazo);
  const reduzida = Math.max(0, input.parcelaReduzida || 0);
  const cheia = parcelaCheia(reduzida, input.reducaoPct);
  const totalNominal = cheia * prazo;
  const ipca = (input.ipcaAnualPct || 0) / 100;

  // 1) Série nominal (sem reajuste). Fase A = reduzida; fase B = saldo restante diluído.
  const base = new Array<number>(prazo + 1).fill(0); // base[m], m de 1..prazo
  let somaA = 0;
  for (let m = 1; m <= contempla; m++) {
    base[m] = reduzida;
    somaA += reduzida;
  }
  const mesesFaseB = prazo - contempla;
  const restante = totalNominal - somaA;
  if (mesesFaseB > 0) {
    const parcelaB = restante / mesesFaseB;
    for (let m = contempla + 1; m <= prazo; m++) base[m] = parcelaB;
  } else {
    base[prazo] += restante; // contemplação no fim: liquida a diferença na última parcela
  }

  // 2) Aplica o reajuste anual (IPCA compõe a cada 12 meses) e agrega.
  const atual = new Array<number>(prazo + 1).fill(0);
  let totalEstimado = 0;
  for (let m = 1; m <= prazo; m++) {
    const fator = Math.pow(1 + ipca, Math.floor((m - 1) / 12));
    atual[m] = base[m] * fator;
    totalEstimado += atual[m];
  }

  const rows: ProjecaoRow[] = [];
  let pago = 0;
  for (let m = 1; m <= prazo; m++) {
    pago += atual[m];
    rows.push({
      mes: m,
      ano: Math.ceil(m / 12),
      parcela: atual[m],
      pagoAcumulado: pago,
      saldoDevedor: Math.max(0, totalEstimado - pago),
      fase: m <= contempla ? "reduzida" : "cheia",
    });
  }

  const pagas = Math.min(Math.max(0, Math.round(input.parcelasPagas || 0)), prazo);
  let jaPago = 0;
  for (let m = 1; m <= pagas; m++) jaPago += atual[m];

  const parcelaPos = mesesFaseB > 0 ? atual[contempla + 1] : atual[prazo];

  return {
    rows,
    totais: {
      parcelaCheia: cheia,
      totalNominal,
      totalEstimado,
      jaPago,
      falta: Math.max(0, totalEstimado - jaPago),
      parcelaHoje: reduzida,
      parcelaPosContemplacao: parcelaPos,
    },
  };
}

export interface ProjecaoContempladoInput {
  prazo: number;
  parcelasPagas: number;
  mensalidade: number; // parcela atual
  valorPagoInicial: number; // total já pago (histórico real)
  ipcaAnualPct: number;
}

/**
 * Projeção de uma cota JÁ CONTEMPLADA: não há fase reduzida nem contemplação futura.
 * O histórico já pago entra como baseline e as parcelas restantes seguem a parcela atual
 * com reajuste anual (IPCA). Retorna a mesma forma de Projecao (mês 1..prazo) para reusar
 * os helpers de gráfico.
 */
export function buildProjecaoContemplado(input: ProjecaoContempladoInput): Projecao {
  const prazo = Math.max(1, Math.round(input.prazo || 1));
  const pagas = Math.min(Math.max(0, Math.round(input.parcelasPagas || 0)), prazo);
  const mensalidade = Math.max(0, input.mensalidade || 0);
  const valorPagoInicial = Math.max(0, input.valorPagoInicial || 0);
  const ipca = (input.ipcaAnualPct || 0) / 100;

  const atual = new Array<number>(prazo + 1).fill(0);
  // Histórico já pago: distribui o total igualmente só para a curva subir de forma suave.
  const mediaHist = pagas > 0 ? valorPagoInicial / pagas : 0;
  for (let m = 1; m <= pagas; m++) atual[m] = mediaHist;
  // Restantes: parcela atual com reajuste anual a partir de agora.
  for (let m = pagas + 1; m <= prazo; m++) {
    const anoOffset = Math.floor((m - pagas - 1) / 12);
    atual[m] = mensalidade * Math.pow(1 + ipca, anoOffset);
  }

  let totalEstimado = 0;
  for (let m = 1; m <= prazo; m++) totalEstimado += atual[m];

  const rows: ProjecaoRow[] = [];
  let pago = 0;
  for (let m = 1; m <= prazo; m++) {
    pago += atual[m];
    rows.push({
      mes: m,
      ano: Math.ceil(m / 12),
      parcela: atual[m],
      pagoAcumulado: pago,
      saldoDevedor: Math.max(0, totalEstimado - pago),
      fase: m <= pagas ? "reduzida" : "cheia", // "reduzida" = histórico já pago
    });
  }

  return {
    rows,
    totais: {
      parcelaCheia: mensalidade,
      totalNominal: valorPagoInicial + mensalidade * (prazo - pagas),
      totalEstimado,
      jaPago: valorPagoInicial,
      falta: Math.max(0, totalEstimado - valorPagoInicial),
      parcelaHoje: mensalidade,
      parcelaPosContemplacao: atual[prazo], // reaproveitado p/ "última parcela (reajustada)"
    },
  };
}

export interface ProjecaoAnualPonto {
  ano: string; // "0a", "1a", ...
  pago: number;
  saldo: number;
  parcela: number;
}

/** Amostra a projeção em pontos anuais (para o gráfico de evolução). */
export function projecaoAnual(proj: Projecao): ProjecaoAnualPonto[] {
  const rows = proj.rows;
  const prazo = rows.length;
  const pontos: ProjecaoAnualPonto[] = [
    { ano: "0a", pago: 0, saldo: proj.totais.totalEstimado, parcela: rows[0]?.parcela ?? 0 },
  ];
  const anos = Math.ceil(prazo / 12);
  for (let y = 1; y <= anos; y++) {
    const m = Math.min(y * 12, prazo);
    const r = rows[m - 1];
    pontos.push({ ano: `${y}a`, pago: r.pagoAcumulado, saldo: r.saldoDevedor, parcela: r.parcela });
  }
  return pontos;
}

export interface CenarioIpca {
  key: string;
  ipca: number;
}

export interface ComparativoIpca {
  data: Array<Record<string, number | string>>; // { ano, [cenario]: pagoAcumulado }
  totais: Array<{ key: string; ipca: number; total: number }>;
}

/**
 * Compara o desembolso acumulado por cenário de IPCA. Recebe um construtor que monta a
 * projeção para um dado IPCA (serve tanto para a cota nova quanto para a contemplada).
 */
export function comparativoIpca(
  buildFor: (ipca: number) => Projecao,
  cenarios: CenarioIpca[],
): ComparativoIpca {
  const built = cenarios.map((c) => {
    const proj = buildFor(c.ipca);
    return { key: c.key, ipca: c.ipca, anual: projecaoAnual(proj), total: proj.totais.totalEstimado };
  });

  const anos = built[0]?.anual.length ?? 0;
  const data: Array<Record<string, number | string>> = [];
  for (let idx = 0; idx < anos; idx++) {
    const row: Record<string, number | string> = { ano: built[0].anual[idx].ano };
    for (const b of built) row[b.key] = Math.round(b.anual[idx]?.pago ?? 0);
    data.push(row);
  }

  return { data, totais: built.map((b) => ({ key: b.key, ipca: b.ipca, total: b.total })) };
}
