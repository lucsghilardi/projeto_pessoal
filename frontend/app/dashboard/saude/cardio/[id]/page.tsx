"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import {
  ArrowLeft,
  Brain,
  Flame,
  Gauge,
  Heart,
  Mountain,
  Ruler,
  Timer,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Line,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { MODALIDADES } from "@/components/saude/cardio-sheet";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatDate, formatFullDate } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  analisarSaudeCardio,
  getSaudeCardioSessao,
  sincronizarSaudeCardioDetalhe,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeCardioDetalhePagina,
  SaudeCardioModalidade,
  SaudeCardioPacingVeredito,
} from "@/types/Saude";

const LABEL_MODALIDADE: Record<SaudeCardioModalidade, string> = Object.fromEntries(
  MODALIDADES.map((m) => [m.value, m.label]),
) as Record<SaudeCardioModalidade, string>;

/** Cores das zonas de FC, do mais leve ao mais intenso. */
const ZONAS = [
  { nome: "Z1 · Aquecimento", cor: "#94a3b8" },
  { nome: "Z2 · Base aeróbica", cor: "#10b981" },
  { nome: "Z3 · Aeróbico", cor: "#3b82f6" },
  { nome: "Z4 · Limiar", cor: "#f59e0b" },
  { nome: "Z5 · Máximo", cor: "#ef4444" },
];

const LABEL_PACING: Record<SaudeCardioPacingVeredito, string> = {
  negative_split: "Negative split — acelerou no fim",
  even: "Ritmo constante",
  positive_split: "Positive split — caiu no fim",
  irregular: "Ritmo irregular (intervalado ou percurso com paradas)",
  indefinido: "Sem voltas suficientes para avaliar",
};

/** Segundos -> "7:04" (usado para pace e para tempos curtos). */
function minSeg(segundos: number) {
  const total = Math.max(0, Math.round(segundos));
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

/** Segundos -> "39:35" ou "1:05:12". */
function duracao(segundos: number | null) {
  if (segundos === null) return "—";
  const total = Math.max(0, Math.round(segundos));
  const h = Math.floor(total / 3600);
  const resto = minSeg(total % 3600);
  return h > 0 ? `${h}:${resto.padStart(5, "0")}` : resto;
}

function pace(segundos: number | null) {
  return segundos === null ? "—" : `${minSeg(segundos)}/km`;
}

function km(valor: number | null) {
  if (valor === null) return "—";
  return `${valor.toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} km`;
}

export default function CardioDetalhePage() {
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);

  const [dados, setDados] = useState<SaudeCardioDetalhePagina | null>(null);
  const [loading, setLoading] = useState(true);
  const [buscandoDetalhe, setBuscandoDetalhe] = useState(false);
  const [analisando, setAnalisando] = useState(false);
  // O detalhe é buscado no Garmin uma vez só: sem esta trava, um erro de rede
  // faria a tela tentar de novo a cada render.
  const tentouDetalhe = useRef(false);

  const load = useCallback(async () => {
    if (!Number.isFinite(id)) return;
    setDados(await getSaudeCardioSessao(id));
  }, [id]);

  useEffect(() => {
    let ativo = true;

    (async () => {
      try {
        await load();
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível carregar a corrida.",
        );
      } finally {
        if (ativo) setLoading(false);
      }
    })();

    return () => {
      ativo = false;
    };
  }, [load]);

  // Splits e zonas só existem depois de uma ida ao Garmin — dispara sozinho na
  // primeira vez que a corrida é aberta.
  useEffect(() => {
    if (!dados || tentouDetalhe.current) return;
    if (dados.detalhe !== null || dados.sessao.garmin_activity_id === null) return;

    tentouDetalhe.current = true;

    (async () => {
      setBuscandoDetalhe(true);
      try {
        await sincronizarSaudeCardioDetalhe(dados.sessao.id);
        await load();
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível buscar os detalhes no Garmin.",
        );
      } finally {
        setBuscandoDetalhe(false);
      }
    })();
  }, [dados, load]);

  const handleAnalisar = async (forcar: boolean) => {
    if (!dados) return;

    setAnalisando(true);
    try {
      await analisarSaudeCardio(dados.sessao.id, forcar);
      await load();
      appToast.success(forcar ? "Análise refeita." : "Análise pronta.");
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível analisar a corrida.",
      );
    } finally {
      setAnalisando(false);
    }
  };

  const graficoSplits = useMemo(() => {
    return (dados?.splits?.voltas ?? []).map((volta) => ({
      km: volta.numero,
      pace: volta.pace_seg_km,
      fc: volta.fc_media,
      cadencia: volta.cadencia,
    }));
  }, [dados]);

  const graficoZonas = useMemo(() => {
    return (dados?.detalhe?.zonas_fc ?? [])
      .filter((zona) => zona.segundos > 0)
      .map((zona) => ({
        nome: ZONAS[zona.zona - 1]?.nome ?? `Z${zona.zona}`,
        cor: ZONAS[zona.zona - 1]?.cor ?? "#94a3b8",
        minutos: Math.round((zona.segundos / 60) * 10) / 10,
        fcMinima: zona.fc_minima,
      }));
  }, [dados]);

  const graficoNorma = useMemo(() => {
    const faixa = dados?.benchmarks.faixa_vo2max;
    const meu = dados?.benchmarks.vo2max_relogio;
    if (!faixa || meu === null || meu === undefined) return [];

    return [
      { rotulo: "p25", valor: faixa["25"], voce: false },
      { rotulo: "Mediana", valor: faixa["50"], voce: false },
      { rotulo: "Você", valor: meu, voce: true },
      { rotulo: "p75", valor: faixa["75"], voce: false },
      { rotulo: "p90", valor: faixa["90"], voce: false },
    ].filter((item) => typeof item.valor === "number");
  }, [dados]);

  if (loading) return <DashboardPageLoader label="Carregando corrida..." />;

  if (!dados) {
    return (
      <div className="space-y-5">
        <VoltarLink />
        <p className="py-8 text-center text-sm text-muted-foreground">
          Corrida não encontrada.
        </p>
      </div>
    );
  }

  const { sessao, detalhe, metricas, splits, benchmarks, evolucao, analise } = dados;
  const paceMaisRapido = Math.min(...graficoSplits.map((v) => v.pace), Infinity);
  const temCadencia = graficoSplits.some((v) => v.cadencia !== null);

  return (
    <div className="space-y-5">
      <VoltarLink />

      <DashboardPageHeader
        title={sessao.nome ?? LABEL_MODALIDADE[sessao.modalidade] ?? "Corrida"}
        description={[
          formatFullDate(sessao.data),
          sessao.horario ? `às ${sessao.horario.slice(0, 5)}` : null,
          LABEL_MODALIDADE[sessao.modalidade],
          sessao.origem === "garmin" ? "Garmin" : "lançamento manual",
        ]
          .filter(Boolean)
          .join(" · ")}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              onClick={() => handleAnalisar(analise !== null)}
              disabled={analisando || buscandoDetalhe}
            >
              {analisando ? <Spinner data-icon="inline-start" /> : <Brain className="size-4" />}
              {analise ? "Refazer análise" : "Analisar com IA"}
            </Button>
          </div>
        }
      />

      {buscandoDetalhe ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner /> Buscando os detalhes desta corrida no Garmin...
        </p>
      ) : null}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <SummaryCard
          label="Distância"
          value={km(metricas.distancia_km)}
          icon={<Ruler className="size-3.5" />}
        />
        <SummaryCard
          label="Tempo"
          value={duracao(metricas.duracao_seg)}
          icon={<Timer className="size-3.5" />}
        />
        <SummaryCard
          label="Ritmo médio"
          value={pace(metricas.pace_seg_km)}
          icon={<Gauge className="size-3.5" />}
        />
        <SummaryCard
          label="FC média"
          value={sessao.fc_media ? `${sessao.fc_media} bpm` : "—"}
          icon={<Heart className="size-3.5" />}
        />
        <SummaryCard
          label="Calorias"
          value={sessao.calorias ? `${sessao.calorias} kcal` : "—"}
          icon={<Flame className="size-3.5" />}
        />
        <SummaryCard
          label="Elevação"
          value={detalhe?.elevacao_ganho_m != null ? `+${detalhe.elevacao_ganho_m} m` : "—"}
          icon={<Mountain className="size-3.5" />}
        />
      </div>

      {splits ? (
        <Card>
          <CardHeader>
            <CardTitle>Ritmo e frequência cardíaca por quilômetro</CardTitle>
            <CardDescription>
              {LABEL_PACING[splits.veredito]}
              {splits.variacao_pct !== null && splits.veredito !== "irregular"
                ? ` · segunda metade ${splits.variacao_pct > 0 ? "mais lenta" : "mais rápida"} em ${Math.abs(splits.variacao_pct)}%`
                : ""}
              {splits.deriva_fc_bpm !== null
                ? ` · FC ${splits.deriva_fc_bpm >= 0 ? "subiu" : "caiu"} ${Math.abs(splits.deriva_fc_bpm)} bpm entre as metades`
                : ""}
            </CardDescription>
          </CardHeader>
          <CardContent className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart
                data={graficoSplits}
                margin={{ top: 8, right: 8, bottom: 0, left: 8 }}
              >
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                <XAxis
                  dataKey="km"
                  tickFormatter={(valor) => `${valor}`}
                  tickLine={false}
                  axisLine={false}
                  fontSize={12}
                />
                {/* Eixo invertido: mais alto no gráfico = mais rápido. */}
                <YAxis
                  yAxisId="pace"
                  reversed
                  domain={["dataMin - 20", "dataMax + 20"]}
                  tickFormatter={(valor) => minSeg(Number(valor))}
                  tickLine={false}
                  axisLine={false}
                  width={44}
                  fontSize={12}
                />
                <YAxis
                  yAxisId="fc"
                  orientation="right"
                  domain={["dataMin - 10", "dataMax + 10"]}
                  tickLine={false}
                  axisLine={false}
                  width={36}
                  fontSize={12}
                />
                <RechartsTooltip
                  formatter={(valor, nome) =>
                    nome === "pace"
                      ? [`${minSeg(Number(valor))}/km`, "Ritmo"]
                      : [`${Number(valor)} bpm`, "FC média"]
                  }
                  labelFormatter={(label) => `Quilômetro ${label}`}
                />
                <ReferenceLine
                  yAxisId="pace"
                  y={splits.pace_medio_seg_km}
                  stroke="#f59e0b"
                  strokeDasharray="6 4"
                />
                <Line
                  yAxisId="pace"
                  type="monotone"
                  dataKey="pace"
                  stroke="#10b981"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                />
                <Line
                  yAxisId="fc"
                  type="monotone"
                  dataKey="fc"
                  stroke="#ef4444"
                  strokeWidth={2}
                  dot={false}
                  connectNulls
                />
              </ComposedChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      ) : null}

      <div className="grid gap-5 lg:grid-cols-2">
        {graficoZonas.length > 0 ? (
          <Card>
            <CardHeader>
              <CardTitle>Tempo nas zonas de frequência</CardTitle>
              <CardDescription>
                Onde o esforço realmente ficou, em minutos.
                {benchmarks.fc_maxima
                  ? ` FC máxima ${benchmarks.fc_maxima_estimada ? "estimada" : "medida"}: ${benchmarks.fc_maxima} bpm.`
                  : ""}
              </CardDescription>
            </CardHeader>
            <CardContent className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  layout="vertical"
                  data={graficoZonas}
                  margin={{ top: 8, right: 16, bottom: 0, left: 8 }}
                >
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-muted" />
                  <XAxis
                    type="number"
                    tickLine={false}
                    axisLine={false}
                    fontSize={12}
                    tickFormatter={(valor) => `${valor}m`}
                  />
                  <YAxis
                    type="category"
                    dataKey="nome"
                    tickLine={false}
                    axisLine={false}
                    width={132}
                    fontSize={11}
                  />
                  <RechartsTooltip
                    formatter={(valor) => [`${Number(valor)} min`, "Tempo"]}
                    labelFormatter={(label, payload) => {
                      const fc = payload?.[0]?.payload?.fcMinima;
                      return fc ? `${label} (a partir de ${fc} bpm)` : String(label);
                    }}
                  />
                  <Bar dataKey="minutos" radius={[0, 3, 3, 0]}>
                    {graficoZonas.map((zona) => (
                      <Cell key={zona.nome} fill={zona.cor} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        ) : null}

        {temCadencia ? (
          <Card>
            <CardHeader>
              <CardTitle>Cadência por quilômetro</CardTitle>
              <CardDescription>
                Passos por minuto.
                {detalhe?.cadencia_media ? ` Média da sessão: ${detalhe.cadencia_media} ppm.` : ""}
                {detalhe?.passada_media_cm
                  ? ` Passada média: ${detalhe.passada_media_cm} cm.`
                  : ""}
              </CardDescription>
            </CardHeader>
            <CardContent className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart
                  data={graficoSplits}
                  margin={{ top: 8, right: 8, bottom: 0, left: 8 }}
                >
                  <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                  <XAxis dataKey="km" tickLine={false} axisLine={false} fontSize={12} />
                  <YAxis
                    domain={["dataMin - 5", "dataMax + 5"]}
                    tickLine={false}
                    axisLine={false}
                    width={36}
                    fontSize={12}
                  />
                  <RechartsTooltip
                    formatter={(valor) => [`${Number(valor)} ppm`, "Cadência"]}
                    labelFormatter={(label) => `Quilômetro ${label}`}
                  />
                  <Line
                    type="monotone"
                    dataKey="cadencia"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    dot={{ r: 3 }}
                    connectNulls
                  />
                </ComposedChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        ) : null}
      </div>

      {evolucao.length > 1 ? (
        <Card>
          <CardHeader>
            <CardTitle>Evolução em {LABEL_MODALIDADE[sessao.modalidade]?.toLowerCase()}</CardTitle>
            <CardDescription>
              Últimas {evolucao.length} sessões. A linha azul é quantos batimentos cada
              quilômetro custou — ela melhora antes do ritmo quando o condicionamento sobe.
            </CardDescription>
          </CardHeader>
          <CardContent className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart
                data={evolucao}
                margin={{ top: 8, right: 8, bottom: 0, left: 8 }}
              >
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                <XAxis
                  dataKey="data"
                  tickFormatter={formatDate}
                  tickLine={false}
                  axisLine={false}
                  fontSize={12}
                  minTickGap={20}
                />
                <YAxis
                  yAxisId="pace"
                  reversed
                  domain={["dataMin - 20", "dataMax + 20"]}
                  tickFormatter={(valor) => minSeg(Number(valor))}
                  tickLine={false}
                  axisLine={false}
                  width={44}
                  fontSize={12}
                />
                <YAxis
                  yAxisId="bpm"
                  orientation="right"
                  domain={["dataMin - 20", "dataMax + 20"]}
                  tickLine={false}
                  axisLine={false}
                  width={40}
                  fontSize={12}
                />
                <RechartsTooltip
                  formatter={(valor, nome) =>
                    nome === "pace_seg_km"
                      ? [`${minSeg(Number(valor))}/km`, "Ritmo"]
                      : [`${Number(valor)} bpm/km`, "Custo cardíaco"]
                  }
                  labelFormatter={(label) => formatFullDate(String(label))}
                />
                <Line
                  yAxisId="pace"
                  type="monotone"
                  dataKey="pace_seg_km"
                  stroke="#10b981"
                  strokeWidth={2}
                  connectNulls
                  dot={(props) => {
                    const { cx, cy, payload, index } = props;
                    return (
                      <circle
                        key={index}
                        cx={cx}
                        cy={cy}
                        r={payload.atual ? 6 : 3}
                        fill={payload.atual ? "#047857" : "#10b981"}
                        stroke="none"
                      />
                    );
                  }}
                />
                <Line
                  yAxisId="bpm"
                  type="monotone"
                  dataKey="batimentos_por_km"
                  stroke="#3b82f6"
                  strokeWidth={2}
                  dot={false}
                  connectNulls
                />
              </ComposedChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      ) : null}

      <Comparativo benchmarks={benchmarks} graficoNorma={graficoNorma} />

      {analise ? (
        <AnaliseIA analise={analise} />
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Análise por IA</CardTitle>
            <CardDescription>
              Um treinador e nutricionista lendo esta corrida junto com seu perfil, sono,
              histórico de treinos e o que você comeu no dia.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button onClick={() => handleAnalisar(false)} disabled={analisando || buscandoDetalhe}>
              {analisando ? <Spinner data-icon="inline-start" /> : <Brain className="size-4" />}
              Analisar esta corrida
            </Button>
          </CardContent>
        </Card>
      )}

      {splits && splits.voltas.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Voltas</CardTitle>
            <CardDescription>Barra mais longa é volta mais rápida.</CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Km</TableHead>
                  <TableHead>Ritmo</TableHead>
                  <TableHead className="text-center">Tempo</TableHead>
                  <TableHead className="text-center">FC média</TableHead>
                  <TableHead className="text-center">FC máx</TableHead>
                  <TableHead className="text-center">Cadência</TableHead>
                  <TableHead className="text-center">Elevação</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {splits.voltas.map((volta) => (
                  <TableRow key={volta.numero}>
                    <TableCell className="tabular-nums">
                      {volta.numero}
                      {volta.distancia_m < 950 ? (
                        <span className="text-xs text-muted-foreground">
                          {" "}
                          ({volta.distancia_m} m)
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <span className="w-16 tabular-nums">{minSeg(volta.pace_seg_km)}</span>
                        <span
                          className="h-2 rounded-sm bg-emerald-500"
                          style={{
                            width: `${Math.round((paceMaisRapido / volta.pace_seg_km) * 100)}%`,
                            maxWidth: "160px",
                            minWidth: "8px",
                          }}
                        />
                      </div>
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {duracao(volta.duracao_seg)}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {volta.fc_media ?? "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {volta.fc_maxima ?? "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {volta.cadencia ?? "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {volta.elevacao_ganho_m != null ? `+${volta.elevacao_ganho_m} m` : "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}

function VoltarLink() {
  return (
    <Link
      href="/dashboard/saude/cardio"
      className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline"
    >
      <ArrowLeft className="size-3.5" />
      Voltar para o cardio
    </Link>
  );
}

function Comparativo({
  benchmarks,
  graficoNorma,
}: {
  benchmarks: SaudeCardioDetalhePagina["benchmarks"];
  graficoNorma: Array<{ rotulo: string; valor: number; voce: boolean }>;
}) {
  const {
    percentil,
    age_grade: ageGrade,
    projecoes,
    projecao_peso: projecaoPeso,
    esforco_maximo: esforcoMaximo,
  } = benchmarks;

  // A divergência entre os dois VO2max só é sintoma de relógio descalibrado se
  // a corrida foi em esforço de prova. Em treino leve ela é esperada.
  const discrepancia =
    esforcoMaximo &&
    benchmarks.vo2max_relogio !== null &&
    benchmarks.vo2max_desempenho !== null
      ? Math.abs(benchmarks.vo2max_relogio - benchmarks.vo2max_desempenho)
      : null;

  const percentualFc =
    benchmarks.esforco_fracao_fcmax !== null
      ? Math.round(benchmarks.esforco_fracao_fcmax * 100)
      : null;

  if (benchmarks.idade === null) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Comparativo com a sua faixa etária</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">
            Preencha sexo e data de nascimento no perfil de saúde para liberar o comparativo.
          </p>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Comparativo com a sua faixa etária</CardTitle>
        <CardDescription>
          {benchmarks.idade} anos
          {benchmarks.peso_kg ? ` · ${benchmarks.peso_kg} kg` : ""} · normas do Cooper
          Institute (ACSM) e tabelas de estrada da WMA.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {graficoNorma.length > 0 ? (
          <div className="h-52">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={graficoNorma} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                <XAxis dataKey="rotulo" tickLine={false} axisLine={false} fontSize={12} />
                <YAxis
                  domain={[0, "dataMax + 6"]}
                  tickLine={false}
                  axisLine={false}
                  width={32}
                  fontSize={12}
                />
                <RechartsTooltip
                  formatter={(valor) => [`${Number(valor)} ml/kg/min`, "VO2max"]}
                />
                <Bar dataKey="valor" radius={[3, 3, 0, 0]}>
                  {graficoNorma.map((item) => (
                    <Cell key={item.rotulo} fill={item.voce ? "#047857" : "#cbd5e1"} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Metrica
            rotulo="VO2max do relógio"
            valor={benchmarks.vo2max_relogio ? `${benchmarks.vo2max_relogio}` : "—"}
            detalhe={
              percentil
                ? `Percentil ${percentil.percentil} · ${percentil.classificacao}`
                : "Sem estimativa do Garmin"
            }
          />
          <Metrica
            rotulo="Idade fitness"
            valor={benchmarks.idade_fitness ? `${benchmarks.idade_fitness} anos` : "—"}
            detalhe={`Sua idade real: ${benchmarks.idade} anos`}
          />
          <Metrica
            rotulo={esforcoMaximo ? "VO2max desta corrida" : "Esta corrida valeu"}
            valor={
              esforcoMaximo
                ? benchmarks.vo2max_desempenho
                  ? `${benchmarks.vo2max_desempenho}`
                  : "—"
                : ageGrade
                  ? `${ageGrade.percentual}%`
                  : "—"
            }
            detalhe={
              esforcoMaximo
                ? benchmarks.percentil_desempenho
                  ? `Percentil ${benchmarks.percentil_desempenho.percentil} · pelo tempo real`
                  : "Precisa de distância e tempo"
                : "Age grade do ritmo desta sessão"
            }
          />
          <Metrica
            rotulo="Intensidade"
            valor={percentualFc ? `${percentualFc}%` : "—"}
            detalhe={
              percentualFc
                ? esforcoMaximo
                  ? "da FC máxima · esforço de prova"
                  : "da FC máxima · treino, não prova"
                : "Sem FC média registrada"
            }
          />
        </div>

        {!esforcoMaximo ? (
          <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
            Esta sessão foi em intensidade de treino
            {percentualFc ? ` (${percentualFc}% da FC máxima)` : ""}, não de prova. O age
            grade acima diz quanto <em>este ritmo</em> valeria numa competição — não é a
            sua capacidade. Para medir forma pelo desempenho, o comparativo precisa de uma
            corrida no limite, acima de 88% da FC máxima. Quem responde por condicionamento
            aqui é o VO2max do relógio, que resume semanas de treino.
          </p>
        ) : null}

        {discrepancia !== null && discrepancia > 5 ? (
          <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
            Mesmo em esforço de prova, o VO2max que o relógio estima (
            {benchmarks.vo2max_relogio}) está {discrepancia.toFixed(1)} pontos acima do que
            este desempenho indica ({benchmarks.vo2max_desempenho}). Normalmente isso
            significa que a FC máxima configurada no relógio não corresponde à real. Vale
            medir num teste para calibrar as zonas.
          </p>
        ) : null}

        {projecoes ? (
          <div>
            <p className="mb-2 text-sm font-medium">
              {projecoes.fonte === "garmin"
                ? "Previsão de prova do Garmin"
                : "Projeção a partir desta corrida"}
            </p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              {Object.entries(projecoes.tempos).map(([prova, segundos]) => (
                <div key={prova} className="rounded-md border p-3">
                  <p className="text-xs text-muted-foreground uppercase">{prova}</p>
                  <p className="text-lg font-semibold tabular-nums">{duracao(segundos)}</p>
                </div>
              ))}
            </div>
          </div>
        ) : null}

        {projecaoPeso ? (
          <p className="text-sm text-muted-foreground">
            Não existe tabela de percentil por peso — mas o peso entra na conta, porque o
            VO2max é medido por quilo. Chegando aos {projecaoPeso.peso_alvo_kg} kg com o
            mesmo condicionamento, o ganho de ritmo seria de no máximo{" "}
            <strong>{projecaoPeso.ganho_seg_km} s/km</strong> (
            {minSeg(projecaoPeso.pace_atual_seg_km)} → {minSeg(projecaoPeso.pace_projetado_seg_km)}).
            É um teto teórico: supõe que cada quilo perdido seja só gordura e que a força
            se mantenha.
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

function Metrica({
  rotulo,
  valor,
  detalhe,
}: {
  rotulo: string;
  valor: string;
  detalhe: string;
}) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{rotulo}</p>
      <p className="text-2xl font-semibold tabular-nums">{valor}</p>
      <p className="text-xs text-muted-foreground">{detalhe}</p>
    </div>
  );
}

function AnaliseIA({ analise }: { analise: NonNullable<SaudeCardioDetalhePagina["analise"]> }) {
  const d = analise.dados;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Brain className="size-4" />
          Análise por IA
        </CardTitle>
        <CardDescription>
          Nota {d.nota_geral}/10 · gerada em {formatFullDate(analise.gerado_em)} com{" "}
          {analise.modelo}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5 text-sm leading-6">
        <p>{d.resumo}</p>

        <div className="grid gap-4 md:grid-cols-2">
          <Bloco titulo="Distribuição de ritmo">
            <p className="font-medium">{LABEL_PACING[d.pacing.veredito]}</p>
            <p>{d.pacing.comentario}</p>
          </Bloco>
          <Bloco titulo="Esforço">
            <p className="font-medium">{d.esforco.zona_predominante}</p>
            <p>{d.esforco.deriva_cardiaca}</p>
            <p>{d.esforco.comentario}</p>
          </Bloco>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          {d.pontos_fortes.length > 0 ? (
            <Bloco titulo="Pontos fortes">
              <ul className="list-disc space-y-1 pl-4">
                {d.pontos_fortes.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </Bloco>
          ) : null}
          {d.pontos_de_atencao.length > 0 ? (
            <Bloco titulo="Pontos de atenção">
              <ul className="list-disc space-y-1 pl-4">
                {d.pontos_de_atencao.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            </Bloco>
          ) : null}
        </div>

        <Bloco titulo="Como você se compara">
          <p>{d.comparativo}</p>
        </Bloco>

        <Bloco titulo="Nutrição">
          <dl className="grid gap-2 md:grid-cols-2">
            <Item termo="Pré-treino" texto={d.nutricao.pre_treino} />
            <Item termo="Durante" texto={d.nutricao.durante} />
            <Item termo="Pós-treino" texto={d.nutricao.pos_treino} />
            <Item termo="Hidratação" texto={d.nutricao.hidratacao} />
          </dl>
        </Bloco>

        <Bloco titulo="Próximo treino">
          <p className="font-medium">
            {d.proximo_treino.tipo} · {d.proximo_treino.quando}
          </p>
          <p>{d.proximo_treino.descricao}</p>
        </Bloco>

        <Bloco titulo="Meta para as próximas 4 semanas">
          <p>{d.meta_curto_prazo}</p>
        </Bloco>
      </CardContent>
    </Card>
  );
}

function Bloco({ titulo, children }: { titulo: string; children: React.ReactNode }) {
  return (
    <div className="rounded-md bg-muted/40 p-3">
      <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {titulo}
      </p>
      <div className="space-y-1">{children}</div>
    </div>
  );
}

function Item({ termo, texto }: { termo: string; texto: string }) {
  return (
    <div>
      <dt className="text-xs font-medium text-muted-foreground">{termo}</dt>
      <dd>{texto}</dd>
    </div>
  );
}
