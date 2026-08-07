"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Camera,
  ChevronLeft,
  ChevronRight,
  Pencil,
  Plus,
  Sparkles,
  Target,
  Trash2,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { useGarminAutoSync } from "@/hooks/use-garmin-auto-sync";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { MetaSheet } from "@/components/saude/meta-sheet";
import { RefeicaoSheet } from "@/components/saude/refeicao-sheet";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  formatDate,
  formatFullDate,
  formatKg,
  shiftDay,
  todayISO,
  toNumber,
} from "@/lib/format";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import {
  deleteSaudeRefeicao,
  getSaudeMeta,
  getSaudeNutricao,
  getSaudeNutricaoProjecao,
  saudeRefeicaoFotoUrl,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeMeta,
  SaudeNutricaoOverview,
  SaudeNutricaoProjecao,
  SaudeRefeicao,
} from "@/types/Saude";

const TIPO_LABEL: Record<string, string> = {
  cafe_da_manha: "Café da manhã",
  almoco: "Almoço",
  jantar: "Jantar",
  lanche: "Lanche",
  outro: "Refeição",
};

const CONFIANCA_LABEL: Record<string, string> = {
  alta: "confiança alta",
  media: "confiança média",
  baixa: "confiança baixa",
};

const ORIGEM_LABEL: Record<string, string> = {
  whatsapp_foto: "via WhatsApp",
  whatsapp_texto: "via WhatsApp",
  painel_ia: "estimado por IA",
};

function kcal(valor: number) {
  return valor.toLocaleString("pt-BR");
}

function gramas(valor: number) {
  return `${valor.toLocaleString("pt-BR", { maximumFractionDigits: 1 })}g`;
}

export default function CaloriasPage() {
  const [overview, setOverview] = useState<SaudeNutricaoOverview | null>(null);
  const [projecao, setProjecao] = useState<SaudeNutricaoProjecao>(null);
  const [meta, setMeta] = useState<SaudeMeta>(null);
  const [loading, setLoading] = useState(true);
  const [refeicaoSheetOpen, setRefeicaoSheetOpen] = useState(false);
  const [metaSheetOpen, setMetaSheetOpen] = useState(false);
  const [editing, setEditing] = useState<SaudeRefeicao | null>(null);
  const [dia, setDia] = useState(todayISO());
  const [recarregando, setRecarregando] = useState(false);

  const hoje = todayISO();
  const ehHoje = dia === hoje;

  const load = useCallback(async () => {
    const [overviewData, projecaoData, metaData] = await Promise.all([
      getSaudeNutricao(dia),
      getSaudeNutricaoProjecao(),
      getSaudeMeta(),
    ]);

    setOverview(overviewData);
    setProjecao(projecaoData.projecao);
    setMeta(metaData.meta);
  }, [dia]);

  // Puxa as atividades novas do relógio ao abrir a tela — o gasto do exercício
  // muda a meta do dia, então esta página é a mais interessada no sync.
  useGarminAutoSync(load);

  useEffect(() => {
    let mounted = true;
    setRecarregando(true);

    (async () => {
      try {
        await load();
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar o diário alimentar.",
        );
      } finally {
        if (mounted) {
          setLoading(false);
          setRecarregando(false);
        }
      }
    })();

    return () => {
      mounted = false;
    };
  }, [load]);

  const metaCalorias = overview?.metas.calorias ?? null;
  const consumido = overview?.consumido.calorias ?? 0;
  const restante = overview?.restante.calorias ?? null;
  const progresso =
    metaCalorias !== null && metaCalorias > 0
      ? Math.min(100, Math.round((consumido / metaCalorias) * 100))
      : null;

  const historicoChart = useMemo(
    () => overview?.historico ?? [],
    [overview],
  );

  const projecaoChart = useMemo(() => projecao?.pontos ?? [], [projecao]);

  const projecaoDomain = useMemo((): [number, number] | ["auto", "auto"] => {
    if (projecaoChart.length === 0) {
      return ["auto", "auto"];
    }

    const valores: number[] = [];
    for (const p of projecaoChart) {
      if (p.plano !== null) valores.push(p.plano);
      if (p.ritmo_real !== null) valores.push(p.ritmo_real);
    }
    if (projecao?.peso_meta != null) {
      valores.push(projecao.peso_meta);
    }
    if (valores.length === 0) {
      return ["auto", "auto"];
    }

    return [
      Math.floor(Math.min(...valores) - 1),
      Math.ceil(Math.max(...valores) + 1),
    ];
  }, [projecaoChart, projecao]);

  async function handleDelete(refeicao: SaudeRefeicao) {
    if (!window.confirm(`Excluir "${refeicao.nome}"?`)) {
      return;
    }

    try {
      await deleteSaudeRefeicao(refeicao.id);
      appToast.success("Refeição removida.");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover a refeição.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando diário alimentar..." />;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Calorias"
        description="Diário alimentar com IA: mande a foto do prato no WhatsApp ou registre aqui — descreva, anexe a foto e confirme a estimativa."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setMetaSheetOpen(true)}>
              <Target className="size-4" />
              Meta e perfil
            </Button>
            <Button
              onClick={() => {
                setEditing(null);
                setRefeicaoSheetOpen(true);
              }}
            >
              <Plus className="size-4" />
              Registrar refeição
            </Button>
          </div>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        <Button
          variant="outline"
          size="icon"
          aria-label="Dia anterior"
          onClick={() => setDia((atual) => shiftDay(atual, -1))}
        >
          <ChevronLeft className="size-4" />
        </Button>
        <Input
          type="date"
          className="w-auto"
          value={dia}
          max={hoje}
          onChange={(event) => setDia(event.target.value || hoje)}
        />
        <Button
          variant="outline"
          size="icon"
          aria-label="Próximo dia"
          disabled={ehHoje}
          onClick={() => setDia((atual) => shiftDay(atual, 1))}
        >
          <ChevronRight className="size-4" />
        </Button>
        {!ehHoje ? (
          <Button variant="ghost" size="sm" onClick={() => setDia(hoje)}>
            Hoje
          </Button>
        ) : null}
        <p className="text-sm text-muted-foreground">
          {recarregando ? "Carregando..." : formatFullDate(dia)}
        </p>
      </div>

      {overview && !overview.perfil_completo ? (
        <Card className="border-amber-500/40 bg-amber-500/5">
          <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
            <p className="text-sm">
              <span className="font-medium">Complete seu perfil</span> — sexo,
              nascimento, altura, nível de atividade e uma pesagem — para
              calcular sua meta calórica e a projeção de emagrecimento.
            </p>
            <Button size="sm" variant="outline" onClick={() => setMetaSheetOpen(true)}>
              Completar perfil
            </Button>
          </CardContent>
        </Card>
      ) : null}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard
          label={
            metaCalorias !== null
              ? `Consumido de ${kcal(metaCalorias)} kcal`
              : ehHoje
                ? "Consumido hoje"
                : "Consumido no dia"
          }
          value={`${kcal(consumido)} kcal`}
        />
        <SummaryCard
          label={ehHoje ? "Restante hoje" : "Restante no dia"}
          value={
            restante === null
              ? "—"
              : restante >= 0
                ? `${kcal(restante)} kcal`
                : `${kcal(Math.abs(restante))} acima`
          }
          accentClass={
            restante !== null
              ? restante >= 0
                ? "text-emerald-600"
                : "text-red-600"
              : undefined
          }
        />
        <SummaryCard
          label={
            overview?.metas.proteinas_g != null
              ? `Proteínas de ${gramas(overview.metas.proteinas_g)}`
              : ehHoje
                ? "Proteínas hoje"
                : "Proteínas no dia"
          }
          value={gramas(overview?.consumido.proteinas_g ?? 0)}
          accentClass={
            overview?.restante.proteinas_g != null && overview.restante.proteinas_g <= 0
              ? "text-emerald-600"
              : undefined
          }
        />
        <SummaryCard
          label="Déficit planejado"
          value={
            overview?.metas.deficit != null && overview.metas.tdee != null
              ? `${kcal(overview.metas.deficit)} kcal/dia`
              : "—"
          }
        />
      </div>

      {progresso !== null ? (
        <div className="space-y-1">
          <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
              className={cn(
                "h-full rounded-full transition-all",
                restante !== null && restante < 0 ? "bg-red-500" : "bg-emerald-500",
              )}
              style={{ width: `${progresso}%` }}
            />
          </div>
          <p className="text-xs text-muted-foreground">
            {progresso}% da meta do dia
            {overview?.metas.tdee != null
              ? ` · gasto do dia (TDEE): ${kcal(overview.metas.tdee)} kcal`
              : ""}
            {overview?.metas.gasto_dinamico && overview.metas.gasto_exercicio > 0
              ? `, dos quais ${kcal(overview.metas.gasto_exercicio)} kcal de exercício`
              : ""}
          </p>
          {overview?.metas.gasto_dinamico ? (
            <p className="text-xs text-muted-foreground">
              Sua meta sobe conforme você treina — o número muda ao longo do dia.
            </p>
          ) : null}
        </div>
      ) : null}

      <Card>
        <CardHeader>
          <CardTitle>
            {ehHoje ? "Refeições de hoje" : `Refeições de ${formatFullDate(dia)}`}
          </CardTitle>
          <CardDescription>
            {overview && overview.refeicoes.length > 0
              ? `${overview.refeicoes.length} refeição(ões) registrada(s).`
              : `Nada registrado ${ehHoje ? "hoje" : "neste dia"}. Mande a foto do prato no WhatsApp ou registre manualmente.`}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {overview?.refeicoes.map((refeicao) => (
            <div
              key={refeicao.id}
              className="flex items-center gap-3 rounded-lg border p-3"
            >
              {refeicao.foto_path ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={saudeRefeicaoFotoUrl(refeicao.id)}
                  alt={refeicao.nome}
                  className="size-14 shrink-0 rounded-md object-cover"
                />
              ) : (
                <div className="flex size-14 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                  {refeicao.origem === "manual" ? (
                    <Pencil className="size-5" />
                  ) : refeicao.origem === "painel_ia" ? (
                    <Sparkles className="size-5" />
                  ) : (
                    <Camera className="size-5" />
                  )}
                </div>
              )}

              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">
                  {refeicao.nome}
                </p>
                <p className="text-xs text-muted-foreground">
                  {TIPO_LABEL[refeicao.tipo ?? "outro"]} · {refeicao.horario.slice(0, 5)}
                  {refeicao.confianca
                    ? ` · ${CONFIANCA_LABEL[refeicao.confianca]}`
                    : ""}
                  {ORIGEM_LABEL[refeicao.origem] ? ` · ${ORIGEM_LABEL[refeicao.origem]}` : ""}
                </p>
                <p className="text-xs text-muted-foreground">
                  Proteínas {gramas(toNumber(refeicao.proteinas_g))}
                  {refeicao.carboidratos_g
                    ? ` · Carb ${gramas(toNumber(refeicao.carboidratos_g))}`
                    : ""}
                  {refeicao.gorduras_g
                    ? ` · Gord ${gramas(toNumber(refeicao.gorduras_g))}`
                    : ""}
                </p>
              </div>

              <p className="shrink-0 text-right font-semibold tabular-nums">
                {kcal(refeicao.calorias)} <span className="text-xs font-normal">kcal</span>
              </p>

              <div className="flex shrink-0 gap-1">
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={() => {
                    setEditing(refeicao);
                    setRefeicaoSheetOpen(true);
                  }}
                >
                  <Pencil className="size-4" />
                </Button>
                <Button variant="ghost" size="icon" onClick={() => handleDelete(refeicao)}>
                  <Trash2 className="size-4 text-red-600" />
                </Button>
              </div>
            </div>
          ))}
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Últimos 14 dias</CardTitle>
            <CardDescription>
              Clique em um dia para abri-lo acima
              {metaCalorias !== null
                ? ` — a linha tracejada marca a meta de ${kcal(metaCalorias)} kcal.`
                : "."}
            </CardDescription>
          </CardHeader>
          <CardContent className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={historicoChart}
                margin={{ top: 8, right: 8, bottom: 0, left: 8 }}
                className="cursor-pointer"
                onClick={(state) => {
                  const clicado = state?.activeLabel;
                  if (typeof clicado === "string" && clicado.length > 0) {
                    setDia(clicado.slice(0, 10));
                  }
                }}
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  vertical={false}
                  className="stroke-muted"
                />
                <XAxis
                  dataKey="data"
                  tickFormatter={formatDate}
                  tickLine={false}
                  axisLine={false}
                  fontSize={12}
                />
                <YAxis tickLine={false} axisLine={false} width={44} fontSize={12} />
                <RechartsTooltip
                  formatter={(value) => [`${kcal(Number(value))} kcal`, "Consumido"]}
                  labelFormatter={(label) => formatFullDate(String(label))}
                />
                <Bar dataKey="calorias" radius={[4, 4, 0, 0]}>
                  {historicoChart.map((ponto) => {
                    const selecionado = ponto.data.slice(0, 10) === dia;

                    return (
                      <Cell
                        key={ponto.data}
                        fill={
                          metaCalorias !== null && ponto.calorias > metaCalorias
                            ? "#ef4444"
                            : "#10b981"
                        }
                        fillOpacity={selecionado ? 1 : 0.45}
                        stroke={selecionado ? "#0f172a" : undefined}
                        strokeWidth={selecionado ? 1 : 0}
                      />
                    );
                  })}
                </Bar>
                {metaCalorias !== null ? (
                  <ReferenceLine y={metaCalorias} stroke="#f59e0b" strokeDasharray="6 4" />
                ) : null}
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Projeção de emagrecimento</CardTitle>
            <CardDescription>
              {projecao === null
                ? "Complete o perfil e registre pesagens para ver a projeção."
                : projecao.data_prevista_meta
                  ? `No ritmo atual você chega aos ${formatKg(projecao.peso_meta ?? 0)} em ${formatFullDate(projecao.data_prevista_meta)}.`
                  : "Linha sólida: plano. Linha pontilhada: seu ritmo real de pesagens."}
            </CardDescription>
          </CardHeader>
          <CardContent className="h-64">
            {projecaoChart.length === 0 ? (
              <p className="flex h-full items-center justify-center text-sm text-muted-foreground">
                Sem dados suficientes para projetar.
              </p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <LineChart
                  data={projecaoChart}
                  margin={{ top: 8, right: 8, bottom: 0, left: 8 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    vertical={false}
                    className="stroke-muted"
                  />
                  <XAxis
                    dataKey="data"
                    tickFormatter={formatDate}
                    tickLine={false}
                    axisLine={false}
                    fontSize={12}
                  />
                  <YAxis
                    domain={projecaoDomain}
                    tickLine={false}
                    axisLine={false}
                    width={44}
                    fontSize={12}
                  />
                  <RechartsTooltip
                    formatter={(value, name) => [
                      formatKg(Number(value)),
                      name === "plano" ? "Plano" : "Ritmo real",
                    ]}
                    labelFormatter={(label) => formatFullDate(String(label))}
                  />
                  <Line
                    type="monotone"
                    dataKey="plano"
                    stroke="#10b981"
                    strokeWidth={2}
                    dot={false}
                  />
                  <Line
                    type="monotone"
                    dataKey="ritmo_real"
                    stroke="#3b82f6"
                    strokeWidth={2}
                    strokeDasharray="5 4"
                    dot={false}
                  />
                  {projecao?.peso_meta != null ? (
                    <ReferenceLine
                      y={projecao.peso_meta}
                      stroke="#f59e0b"
                      strokeDasharray="6 4"
                    />
                  ) : null}
                </LineChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>
      </div>

      <RefeicaoSheet
        open={refeicaoSheetOpen}
        onOpenChange={setRefeicaoSheetOpen}
        editing={editing}
        defaultData={dia}
        onSaved={load}
      />
      <MetaSheet
        open={metaSheetOpen}
        onOpenChange={setMetaSheetOpen}
        meta={meta}
        onSaved={load}
      />
    </div>
  );
}
