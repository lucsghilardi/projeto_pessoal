"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import {
  Area,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { PrivateChart } from "@/components/dashboard/private-chart";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { toNumber } from "@/lib/format";
import { usePrivateFormat } from "@/hooks/use-private-format";
import { buildProjecao, buildProjecaoContemplado, comparativoIpca, projecaoAnual } from "@/lib/consorcio";
import { appToast } from "@/lib/toast";
import { getConsorcios } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Consorcio } from "@/types/Consorcio";
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

const CENARIO_CORES: Record<string, string> = {
  Pessimista: "#ef4444",
  Realista: "#2563eb",
  Otimista: "#22c55e",
};

function parseNumber(value: string) {
  const n = Number(value.replace(",", "."));
  return Number.isFinite(n) ? n : 0;
}

export default function ConsorcioProjecaoPage() {
  const { compactCurrency, formatCurrency } = usePrivateFormat();
  const params = useParams<{ id: string }>();
  const consorcioId = Number(params.id);

  const [consorcio, setConsorcio] = useState<Consorcio | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  // Premissas ajustáveis ao vivo.
  const [reducaoPct, setReducaoPct] = useState(50);
  const [ipca, setIpca] = useState(4.5);
  const [ipcaPess, setIpcaPess] = useState(6);
  const [ipcaOtim, setIpcaOtim] = useState(3);
  const [contemplacaoMes, setContemplacaoMes] = useState(40);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const list = await getConsorcios();
        if (!mounted) return;
        const found = list.consorcios.find((c) => c.id === consorcioId) ?? null;
        setConsorcio(found);
        setNotFound(!found);
        if (found?.prazo_meses) {
          setContemplacaoMes(Math.max(1, Math.round(found.prazo_meses / 2)));
        }
        if (found?.reducao_pct != null) {
          setReducaoPct(toNumber(found.reducao_pct));
        }
      } catch (error) {
        if (mounted) {
          appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o consórcio.");
        }
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [consorcioId]);

  const contemplado = consorcio?.tipo === "contemplado";
  const credito = consorcio ? toNumber(consorcio.valor_credito ?? "0") : 0;
  const prazo = consorcio?.prazo_meses ?? 0;
  const parcelaReduzida = consorcio ? toNumber(consorcio.valor_mensalidade ?? "0") : 0;
  const parcelasPagas = consorcio?.parcelas_pagas ?? 0;
  const valorPagoInicial = consorcio?.valor_pago_inicial != null
    ? toNumber(consorcio.valor_pago_inicial)
    : parcelasPagas * parcelaReduzida;
  const podeProjetar = prazo > 0 && parcelaReduzida > 0;

  // Construtor da projeção para um dado IPCA — diferente para cota nova x contemplada.
  const buildFor = useMemo(() => {
    if (contemplado) {
      return (ipcaAnualPct: number) =>
        buildProjecaoContemplado({ prazo, parcelasPagas, mensalidade: parcelaReduzida, valorPagoInicial, ipcaAnualPct });
    }
    return (ipcaAnualPct: number) =>
      buildProjecao({ credito, prazo, parcelaReduzida, reducaoPct, contemplacaoMes, parcelasPagas, ipcaAnualPct });
  }, [contemplado, credito, prazo, parcelaReduzida, reducaoPct, contemplacaoMes, parcelasPagas, valorPagoInicial]);

  const proj = useMemo(() => buildFor(ipca), [buildFor, ipca]);

  const anual = useMemo(() => projecaoAnual(proj), [proj]);

  const comparativo = useMemo(
    () =>
      comparativoIpca(buildFor, [
        { key: "Pessimista", ipca: ipcaPess },
        { key: "Realista", ipca },
        { key: "Otimista", ipca: ipcaOtim },
      ]),
    [buildFor, ipca, ipcaPess, ipcaOtim],
  );

  const evolucao = useMemo(
    () =>
      anual.map((p) => ({
        ano: p.ano,
        "Pago acumulado": Math.round(p.pago),
        "Saldo devedor": Math.round(p.saldo),
      })),
    [anual],
  );

  const donut = useMemo(
    () => [
      { name: "Já pago", value: Math.round(proj.totais.jaPago), color: "#22c55e" },
      { name: "Falta pagar", value: Math.round(proj.totais.falta), color: "#e5e7eb" },
    ],
    [proj],
  );

  const anoContemplacao = Math.ceil(contemplacaoMes / 12);
  const aumentoPct =
    proj.totais.parcelaHoje > 0
      ? Math.round((proj.totais.parcelaPosContemplacao / proj.totais.parcelaHoje - 1) * 100)
      : 0;

  if (loading) {
    return <DashboardPageLoader label="Carregando projeção..." />;
  }

  if (notFound || !consorcio) {
    return (
      <div className="space-y-4">
        <Link href="/dashboard/consorcios" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          Voltar
        </Link>
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Consórcio não encontrado.
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <Link
        href={`/dashboard/consorcios/${consorcio.id}`}
        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        Voltar para o consórcio
      </Link>

      <DashboardPageHeader
        title={`Projeção — ${consorcio.nome}`}
        description={`Crédito ${formatCurrency(credito)} · ${prazo} meses · parcela atual ${formatCurrency(parcelaReduzida)}. Ajuste as premissas abaixo para simular.`}
      />

      {!podeProjetar ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Cadastre o <strong>crédito</strong>, o <strong>prazo</strong> e a <strong>mensalidade</strong> do consórcio
            para gerar a projeção.
          </CardContent>
        </Card>
      ) : (
        <>
          {/* Premissas */}
          <Card>
            <CardHeader>
              <CardTitle>Premissas</CardTitle>
              <CardDescription>
                {contemplado
                  ? "Cota já contemplada: projeção das parcelas restantes com reajuste anual (IPCA) sobre a parcela atual."
                  : "A redução é adiamento de fluxo, não desconto: o total tende a se manter; a parcela só muda de patamar após a contemplação."}
              </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
              {!contemplado ? (
                <Field>
                  <FieldLabel htmlFor="p-reducao">Redução até contemplar (%)</FieldLabel>
                  <Input
                    id="p-reducao"
                    type="number"
                    min={0}
                    max={90}
                    step={5}
                    value={reducaoPct}
                    onChange={(e) => setReducaoPct(Math.min(90, Math.max(0, parseNumber(e.target.value))))}
                  />
                  <FieldDescription>Parcela cheia: {formatCurrency(proj.totais.parcelaCheia)}</FieldDescription>
                </Field>
              ) : null}
              <Field>
                <FieldLabel htmlFor="p-ipca">IPCA esperado (% a.a.)</FieldLabel>
                <Input
                  id="p-ipca"
                  type="number"
                  min={0}
                  step={0.5}
                  value={ipca}
                  onChange={(e) => setIpca(Math.max(0, parseNumber(e.target.value)))}
                />
                <FieldDescription>Reajuste anual do crédito.</FieldDescription>
              </Field>
              {!contemplado ? (
                <Field className="sm:col-span-2">
                  <FieldLabel htmlFor="p-contempla">
                    Contemplação no mês {contemplacaoMes} (ano {anoContemplacao})
                  </FieldLabel>
                  <input
                    id="p-contempla"
                    type="range"
                    min={1}
                    max={prazo}
                    step={1}
                    value={contemplacaoMes}
                    onChange={(e) => setContemplacaoMes(Number(e.target.value))}
                    className="w-full accent-[var(--cor-first,#2563eb)]"
                  />
                  <FieldDescription>
                    Por sorteio/lance é incerto — arraste para ver o impacto no fluxo.
                  </FieldDescription>
                </Field>
              ) : null}
            </CardContent>
          </Card>

          {/* Cards-resumo */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <SummaryCard label="Total estimado a pagar" value={formatCurrency(proj.totais.totalEstimado)} />
            <SummaryCard label="Já pago" value={formatCurrency(proj.totais.jaPago)} accentClass="text-emerald-600" />
            <SummaryCard label="Falta pagar" value={formatCurrency(proj.totais.falta)} accentClass="text-amber-600" />
            <Card>
              <CardHeader className="pb-2">
                <CardDescription>
                  {contemplado ? "Parcela atual → última (reajustada)" : "Parcela hoje → após contemplação"}
                </CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-semibold tabular-nums">
                  {formatCurrency(proj.totais.parcelaHoje)}{" "}
                  <span className="text-base text-muted-foreground">→</span>{" "}
                  <span className="text-red-600">{formatCurrency(proj.totais.parcelaPosContemplacao)}</span>
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {contemplado
                    ? `crescimento de ~${aumentoPct}% até a última parcela com IPCA de ${ipca}% a.a.`
                    : `salto de ~${aumentoPct}% ao contemplar no mês ${contemplacaoMes}.`}
                </p>
              </CardContent>
            </Card>
          </div>

          {/* Gráfico 1 — Quanto falta */}
          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-1">
              <CardHeader>
                <CardTitle>Quanto falta</CardTitle>
                <CardDescription>
                  {parcelasPagas} de {prazo} parcelas pagas.
                </CardDescription>
              </CardHeader>
              <CardContent className="h-64">
                <PrivateChart>
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={donut}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={56}
                        outerRadius={88}
                        paddingAngle={2}
                      >
                        {donut.map((d) => (
                          <Cell key={d.name} fill={d.color} />
                        ))}
                      </Pie>
                      <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                </PrivateChart>
              </CardContent>
            </Card>

            {/* Gráfico 2 — Evolução do desembolso */}
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Evolução do desembolso</CardTitle>
                <CardDescription>
                  {contemplado
                    ? "Pago acumulado x saldo devedor, ano a ano, até a quitação das parcelas restantes."
                    : "Pago acumulado x saldo devedor, ano a ano. A linha tracejada marca a contemplação (onde a parcela sobe de patamar)."}
                </CardDescription>
              </CardHeader>
              <CardContent className="h-72">
                <PrivateChart>
                  <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={evolucao} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                      <XAxis dataKey="ano" tickLine={false} axisLine={false} fontSize={12} />
                      <YAxis tickFormatter={compactCurrency} tickLine={false} axisLine={false} width={56} fontSize={12} />
                      <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                      <Legend />
                      <Area
                        type="monotone"
                        dataKey="Pago acumulado"
                        stroke="#22c55e"
                        fill="#22c55e"
                        fillOpacity={0.15}
                        strokeWidth={2}
                      />
                      <Line type="monotone" dataKey="Saldo devedor" stroke="#ef4444" strokeWidth={2} dot={false} />
                      {!contemplado ? <ReferenceLine x={`${anoContemplacao}a`} stroke="#f59e0b" strokeDasharray="6 4" /> : null}
                    </ComposedChart>
                  </ResponsiveContainer>
                </PrivateChart>
              </CardContent>
            </Card>
          </div>

          {/* Gráfico 3 — Cenários de reajuste */}
          <Card>
            <CardHeader>
              <CardTitle>Cenários de reajuste (IPCA)</CardTitle>
              <CardDescription>
                Desembolso acumulado conforme o IPCA. Ajuste as taxas pessimista e otimista.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid gap-6 sm:grid-cols-2">
                <Field>
                  <FieldLabel htmlFor="c-pess">Pessimista (% a.a.)</FieldLabel>
                  <Input
                    id="c-pess"
                    type="number"
                    min={0}
                    step={0.5}
                    value={ipcaPess}
                    onChange={(e) => setIpcaPess(Math.max(0, parseNumber(e.target.value)))}
                  />
                </Field>
                <Field>
                  <FieldLabel htmlFor="c-otim">Otimista (% a.a.)</FieldLabel>
                  <Input
                    id="c-otim"
                    type="number"
                    min={0}
                    step={0.5}
                    value={ipcaOtim}
                    onChange={(e) => setIpcaOtim(Math.max(0, parseNumber(e.target.value)))}
                  />
                </Field>
              </div>

              <div className="h-72">
                <PrivateChart>
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={comparativo.data} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                      <XAxis dataKey="ano" tickLine={false} axisLine={false} fontSize={12} />
                      <YAxis tickFormatter={compactCurrency} tickLine={false} axisLine={false} width={56} fontSize={12} />
                      <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                      <Legend />
                      {comparativo.totais.map((c) => (
                        <Line
                          key={c.key}
                          type="monotone"
                          dataKey={c.key}
                          stroke={CENARIO_CORES[c.key] ?? "#64748b"}
                          strokeWidth={2}
                          dot={false}
                        />
                      ))}
                    </LineChart>
                  </ResponsiveContainer>
                </PrivateChart>
              </div>

              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Cenário</TableHead>
                    <TableHead className="text-right">IPCA (a.a.)</TableHead>
                    <TableHead className="text-right">Total estimado</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {comparativo.totais.map((c) => (
                    <TableRow key={c.key}>
                      <TableCell className="font-medium">
                        <span className="flex items-center gap-2">
                          <span
                            className="inline-block size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: CENARIO_CORES[c.key] ?? "#64748b" }}
                          />
                          {c.key}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{c.ipca}%</TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {formatCurrency(c.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <p className="text-sm text-muted-foreground">
            {contemplado
              ? "Cota já contemplada: o histórico pago entra como base e as parcelas restantes seguem a parcela atual, reajustadas pelo IPCA no aniversário do grupo. O total estimado = já pago + saldo a pagar reajustado."
              : "Como tratar os 50%: a parcela reduzida adia parte do custo — ela não diminui o total. O valor não pago agora vira diferença que é diluída nas parcelas após a contemplação, por isso a parcela sobe de patamar. Some o reajuste anual (IPCA) e o total estimado é o melhor número para se planejar."}
          </p>
        </>
      )}
    </div>
  );
}
