"use client";

import { useEffect, useMemo, useState } from "react";
import {
  Area,
  CartesianGrid,
  ComposedChart,
  Legend,
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
import { compactCurrency, formatCurrency } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { getInvestmentSummary } from "@/services/api";
import { ApiError } from "@/services/apiError";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Field, FieldDescription, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const MONTHS_CAP = 600; // 50 anos: horizonte máximo de projeção.
const DEFAULT_META = 1_000_000;

// Taxa anual (decimal, ex. 0.10) -> taxa mensal equivalente.
function monthlyRate(annual: number) {
  return Math.pow(1 + annual, 1 / 12) - 1;
}

// Saldo após n meses com aporte mensal A e taxa mensal i (juros compostos).
function futureValue(P: number, A: number, i: number, n: number) {
  if (n <= 0) return P;
  if (i === 0) return P + A * n;
  const growth = Math.pow(1 + i, n);
  return P * growth + A * ((growth - 1) / i);
}

// Em quantos meses o saldo atinge a meta (null se não atinge em MONTHS_CAP).
function monthsToGoal(P: number, A: number, i: number, goal: number) {
  if (P >= goal) return 0;
  for (let n = 1; n <= MONTHS_CAP; n++) {
    if (futureValue(P, A, i, n) >= goal) return n;
  }
  return null;
}

// Aporte mensal necessário para atingir a meta em N meses.
function requiredContribution(P: number, i: number, goal: number, N: number) {
  if (N <= 0) return null;
  const growth = Math.pow(1 + i, N);
  const fvCapital = P * growth;
  if (fvCapital >= goal) return 0; // só o capital inicial já chega.
  if (i === 0) return (goal - P) / N;
  return (goal - fvCapital) * (i / (growth - 1));
}

function monthsToText(n: number | null) {
  if (n === null) return "Não atinge em 50 anos";
  if (n === 0) return "Já atingido";
  const years = Math.floor(n / 12);
  const months = n % 12;
  const parts: string[] = [];
  if (years > 0) parts.push(`${years} ${years === 1 ? "ano" : "anos"}`);
  if (months > 0) parts.push(`${months} ${months === 1 ? "mês" : "meses"}`);
  return parts.join(" e ");
}

function estimatedDate(n: number | null) {
  if (n === null || n === 0) return null;
  const d = new Date();
  d.setMonth(d.getMonth() + n);
  return d.toLocaleDateString("pt-BR", { month: "long", year: "numeric" });
}

// Lê um número de um <input>, tratando vazio/inválido como 0.
function parseNumber(value: string) {
  const n = Number(value.replace(",", "."));
  return Number.isFinite(n) ? n : 0;
}

const SCENARIO_COLORS = {
  pessimista: "#ef4444",
  realista: "#2563eb",
  otimista: "#22c55e",
} as const;

export default function Plano1MilhaoPage() {
  const [loading, setLoading] = useState(true);

  // Premissas principais.
  const [capitalInicial, setCapitalInicial] = useState(0);
  const [aporteMensal, setAporteMensal] = useState(0);
  const [taxaAnual, setTaxaAnual] = useState(10); // em % a.a.
  const [meta, setMeta] = useState(DEFAULT_META);

  // Cenário 2 — aporte necessário para um prazo.
  const [prazoAnos, setPrazoAnos] = useState(10);

  // Cenário 3 — comparação de taxas (em % a.a.).
  const [taxaPessimista, setTaxaPessimista] = useState(6);
  const [taxaRealista, setTaxaRealista] = useState(10);
  const [taxaOtimista, setTaxaOtimista] = useState(14);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    (async () => {
      try {
        const summary = await getInvestmentSummary();
        if (mounted) setCapitalInicial(Math.round((summary.totals.current ?? 0) * 100) / 100);
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível carregar a carteira de investimentos.",
        );
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  const i = useMemo(() => monthlyRate(taxaAnual / 100), [taxaAnual]);

  // Cenário 1 — tempo até a meta.
  const reachedMonth = useMemo(
    () => monthsToGoal(capitalInicial, aporteMensal, i, meta),
    [capitalInicial, aporteMensal, i, meta],
  );

  const summary = useMemo(() => {
    const n = reachedMonth ?? MONTHS_CAP;
    const saldoFinal = futureValue(capitalInicial, aporteMensal, i, n);
    const totalAportado = capitalInicial + aporteMensal * n;
    return {
      saldoFinal,
      totalAportado,
      totalJuros: saldoFinal - totalAportado,
    };
  }, [reachedMonth, capitalInicial, aporteMensal, i]);

  // Série anual (gráfico + tabela do cenário 1).
  const yearly = useMemo(() => {
    const horizonMonths = reachedMonth ?? MONTHS_CAP;
    const years = Math.max(1, Math.ceil(horizonMonths / 12));
    const rows: Array<{ ano: number; saldo: number; aportado: number; juros: number }> = [];
    for (let y = 0; y <= years; y++) {
      const n = y * 12;
      const saldo = futureValue(capitalInicial, aporteMensal, i, n);
      const aportado = capitalInicial + aporteMensal * n;
      rows.push({ ano: y, saldo, aportado, juros: saldo - aportado });
    }
    return rows;
  }, [reachedMonth, capitalInicial, aporteMensal, i]);

  const chartData = useMemo(
    () =>
      yearly.map((row) => ({
        ano: `${row.ano}a`,
        Saldo: Math.round(row.saldo),
        "Total aportado": Math.round(row.aportado),
      })),
    [yearly],
  );

  // Cenário 2 — aporte necessário para o prazo escolhido.
  const aporteNecessario = useMemo(
    () => requiredContribution(capitalInicial, i, meta, prazoAnos * 12),
    [capitalInicial, i, meta, prazoAnos],
  );

  // Cenário 3 — comparação de taxas.
  const scenarios = useMemo(() => {
    const defs = [
      { key: "Pessimista" as const, color: SCENARIO_COLORS.pessimista, annual: taxaPessimista },
      { key: "Realista" as const, color: SCENARIO_COLORS.realista, annual: taxaRealista },
      { key: "Otimista" as const, color: SCENARIO_COLORS.otimista, annual: taxaOtimista },
    ];
    return defs.map((def) => {
      const rate = monthlyRate(def.annual / 100);
      const reached = monthsToGoal(capitalInicial, aporteMensal, rate, meta);
      return { ...def, rate, reached };
    });
  }, [capitalInicial, aporteMensal, meta, taxaPessimista, taxaRealista, taxaOtimista]);

  const comparisonData = useMemo(() => {
    const horizonMonths = Math.min(
      MONTHS_CAP,
      Math.max(...scenarios.map((s) => s.reached ?? MONTHS_CAP)),
    );
    const years = Math.max(1, Math.ceil(horizonMonths / 12));
    const rows: Array<Record<string, number | string>> = [];
    for (let y = 0; y <= years; y++) {
      const n = y * 12;
      const row: Record<string, number | string> = { ano: `${y}a` };
      for (const s of scenarios) {
        row[s.key] = Math.round(futureValue(capitalInicial, aporteMensal, s.rate, n));
      }
      rows.push(row);
    }
    return rows;
  }, [scenarios, capitalInicial, aporteMensal]);

  if (loading) {
    return <DashboardPageLoader label="Carregando simulador..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Plano 1 Milhão"
        description="Simule aportes, rentabilidade e prazos para descobrir o caminho até o seu primeiro milhão. O capital inicial vem da sua carteira de investimentos atual e pode ser ajustado livremente."
      />

      {/* Premissas */}
      <Card>
        <CardHeader>
          <CardTitle>Premissas</CardTitle>
          <CardDescription>Ajuste os valores e veja todos os cenários recalcularem na hora.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-6 sm:grid-cols-2 xl:grid-cols-4">
            <Field>
              <FieldLabel htmlFor="capital">Capital inicial (R$)</FieldLabel>
              <Input
                id="capital"
                type="number"
                min={0}
                step={100}
                value={capitalInicial}
                onChange={(e) => setCapitalInicial(parseNumber(e.target.value))}
              />
              <FieldDescription>Valor atual da sua carteira de investimentos.</FieldDescription>
            </Field>
            <Field>
              <FieldLabel htmlFor="aporte">Aporte mensal (R$)</FieldLabel>
              <Input
                id="aporte"
                type="number"
                min={0}
                step={100}
                value={aporteMensal}
                onChange={(e) => setAporteMensal(parseNumber(e.target.value))}
              />
              <FieldDescription>Quanto você pretende investir por mês.</FieldDescription>
            </Field>
            <Field>
              <FieldLabel htmlFor="taxa">Rentabilidade (% a.a.)</FieldLabel>
              <Input
                id="taxa"
                type="number"
                min={0}
                step={0.5}
                value={taxaAnual}
                onChange={(e) => setTaxaAnual(parseNumber(e.target.value))}
              />
              <FieldDescription>Retorno anual estimado dos investimentos.</FieldDescription>
            </Field>
            <Field>
              <FieldLabel htmlFor="meta">Meta (R$)</FieldLabel>
              <Input
                id="meta"
                type="number"
                min={0}
                step={10000}
                value={meta}
                onChange={(e) => setMeta(parseNumber(e.target.value))}
              />
              <FieldDescription>Objetivo a atingir (padrão: 1 milhão).</FieldDescription>
            </Field>
          </div>
        </CardContent>
      </Card>

      {/* Cards de resumo */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Tempo até a meta</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-emerald-600">{monthsToText(reachedMonth)}</p>
            {estimatedDate(reachedMonth) ? (
              <p className="mt-1 text-sm text-muted-foreground capitalize">{estimatedDate(reachedMonth)}</p>
            ) : null}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Patrimônio na meta</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums">{formatCurrency(summary.saldoFinal)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Total aportado</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums">{formatCurrency(summary.totalAportado)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Ganho em juros</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-emerald-600">{formatCurrency(summary.totalJuros)}</p>
          </CardContent>
        </Card>
      </div>

      {/* Cenário 1 — Evolução até a meta */}
      <Card>
        <CardHeader>
          <CardTitle>Evolução até a meta</CardTitle>
          <CardDescription>
            Saldo projetado x total aportado, ano a ano. A linha tracejada marca a meta de {formatCurrency(meta)}.
          </CardDescription>
        </CardHeader>
        <CardContent className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <ComposedChart data={chartData} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
              <XAxis dataKey="ano" tickLine={false} axisLine={false} fontSize={12} />
              <YAxis tickFormatter={compactCurrency} tickLine={false} axisLine={false} width={56} fontSize={12} />
              <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
              <Legend />
              <Area type="monotone" dataKey="Saldo" stroke="#2563eb" fill="#2563eb" fillOpacity={0.15} strokeWidth={2} />
              <Line type="monotone" dataKey="Total aportado" stroke="#64748b" strokeWidth={2} dot={false} />
              <ReferenceLine y={meta} stroke="#f59e0b" strokeDasharray="6 4" />
            </ComposedChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Cenário 2 — Aporte necessário para um prazo */}
      <Card>
        <CardHeader>
          <CardTitle>Aporte necessário</CardTitle>
          <CardDescription>Quanto investir por mês para bater a meta em um prazo definido.</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
            <Field className="sm:max-w-xs">
              <FieldLabel htmlFor="prazo">Prazo desejado (anos)</FieldLabel>
              <Input
                id="prazo"
                type="number"
                min={1}
                step={1}
                value={prazoAnos}
                onChange={(e) => setPrazoAnos(Math.max(1, Math.round(parseNumber(e.target.value))))}
              />
              <FieldDescription>
                Mantendo capital de {formatCurrency(capitalInicial)} e {taxaAnual}% a.a.
              </FieldDescription>
            </Field>
            <div className="rounded-lg border bg-muted/40 px-5 py-4">
              <p className="text-sm text-muted-foreground">Aporte mensal necessário</p>
              <p className="text-3xl font-semibold tabular-nums text-emerald-600">
                {aporteNecessario === null
                  ? "—"
                  : aporteNecessario === 0
                    ? "R$ 0 (já atingido)"
                    : formatCurrency(aporteNecessario)}
              </p>
              <p className="mt-1 text-sm text-muted-foreground">
                para atingir {formatCurrency(meta)} em {prazoAnos} {prazoAnos === 1 ? "ano" : "anos"}.
              </p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Cenário 3 — Comparar cenários de rentabilidade */}
      <Card>
        <CardHeader>
          <CardTitle>Comparar cenários</CardTitle>
          <CardDescription>
            Mesmo capital e aporte, taxas diferentes. Veja o impacto da rentabilidade no prazo até a meta.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="grid gap-6 sm:grid-cols-3">
            <Field>
              <FieldLabel htmlFor="t-pess">Pessimista (% a.a.)</FieldLabel>
              <Input
                id="t-pess"
                type="number"
                min={0}
                step={0.5}
                value={taxaPessimista}
                onChange={(e) => setTaxaPessimista(parseNumber(e.target.value))}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="t-real">Realista (% a.a.)</FieldLabel>
              <Input
                id="t-real"
                type="number"
                min={0}
                step={0.5}
                value={taxaRealista}
                onChange={(e) => setTaxaRealista(parseNumber(e.target.value))}
              />
            </Field>
            <Field>
              <FieldLabel htmlFor="t-otim">Otimista (% a.a.)</FieldLabel>
              <Input
                id="t-otim"
                type="number"
                min={0}
                step={0.5}
                value={taxaOtimista}
                onChange={(e) => setTaxaOtimista(parseNumber(e.target.value))}
              />
            </Field>
          </div>

          <div className="h-80">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={comparisonData} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                <XAxis dataKey="ano" tickLine={false} axisLine={false} fontSize={12} />
                <YAxis tickFormatter={compactCurrency} tickLine={false} axisLine={false} width={56} fontSize={12} />
                <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                <Legend />
                <ReferenceLine y={meta} stroke="#f59e0b" strokeDasharray="6 4" />
                {scenarios.map((s) => (
                  <Line key={s.key} type="monotone" dataKey={s.key} stroke={s.color} strokeWidth={2} dot={false} />
                ))}
              </LineChart>
            </ResponsiveContainer>
          </div>

          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Cenário</TableHead>
                <TableHead className="text-right">Taxa (a.a.)</TableHead>
                <TableHead className="text-right">Tempo até a meta</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {scenarios.map((s) => (
                <TableRow key={s.key}>
                  <TableCell className="font-medium">
                    <span className="flex items-center gap-2">
                      <span
                        className="inline-block size-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: s.color }}
                      />
                      {s.key}
                    </span>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{s.annual}%</TableCell>
                  <TableCell className="text-right tabular-nums">{monthsToText(s.reached)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Tabela ano a ano (cenário principal) */}
      <Card>
        <CardHeader>
          <CardTitle>Detalhamento ano a ano</CardTitle>
          <CardDescription>Evolução do patrimônio no cenário principal ({taxaAnual}% a.a.).</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Ano</TableHead>
                  <TableHead className="text-right">Total aportado</TableHead>
                  <TableHead className="text-right">Juros acumulados</TableHead>
                  <TableHead className="text-right">Saldo</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {yearly.map((row) => (
                  <TableRow key={row.ano}>
                    <TableCell className="font-medium">{row.ano === 0 ? "Hoje" : `${row.ano}º ano`}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatCurrency(row.aportado)}</TableCell>
                    <TableCell className="text-right tabular-nums text-emerald-600">
                      {formatCurrency(row.juros)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-semibold">{formatCurrency(row.saldo)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
