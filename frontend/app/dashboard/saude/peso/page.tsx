"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Pencil, Plus, Target, Trash2 } from "lucide-react";
import {
  CartesianGrid,
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
import { SummaryCard } from "@/components/dashboard/summary-card";
import { MetaSheet } from "@/components/saude/meta-sheet";
import { PesoSheet } from "@/components/saude/peso-sheet";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatDate, formatFullDate, formatKg, toNumber } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import { deleteSaudePeso, getSaudeMeta, getSaudePesos } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeMeta, SaudePeso } from "@/types/Saude";

/** "YYYY-MM-DD" de `dias` atrás, no fuso local. */
function diasAtras(dias: number) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** -1.2 -> "-1,2 kg" / +0.5 -> "+0,5 kg" */
function formatDeltaKg(value: number) {
  const abs = Math.abs(value).toLocaleString("pt-BR", {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  });
  return `${value > 0 ? "+" : value < 0 ? "-" : ""}${abs} kg`;
}

export default function PesoPage() {
  const [pesos, setPesos] = useState<SaudePeso[]>([]);
  const [meta, setMeta] = useState<SaudeMeta>(null);
  const [loading, setLoading] = useState(true);
  const [pesoSheetOpen, setPesoSheetOpen] = useState(false);
  const [metaSheetOpen, setMetaSheetOpen] = useState(false);
  const [editing, setEditing] = useState<SaudePeso | null>(null);

  const load = useCallback(async () => {
    const [pesosData, metaData] = await Promise.all([
      getSaudePesos(),
      getSaudeMeta(),
    ]);

    setPesos(pesosData);
    setMeta(metaData.meta);
  }, []);

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        await load();
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar as pesagens.",
        );
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    })();

    return () => {
      mounted = false;
    };
  }, [load]);

  const atual = pesos.length > 0 ? toNumber(pesos[pesos.length - 1].peso_kg) : null;
  const metaKg = meta?.peso_meta_kg ? toNumber(meta.peso_meta_kg) : null;
  const falta = atual !== null && metaKg !== null ? atual - metaKg : null;
  const imc =
    atual !== null && meta?.altura_cm
      ? atual / Math.pow(meta.altura_cm / 100, 2)
      : null;

  const variacao30 = useMemo(() => {
    const corte = diasAtras(30);
    const janela = pesos.filter((p) => p.data.slice(0, 10) >= corte);
    if (janela.length < 2) {
      return null;
    }

    return (
      toNumber(janela[janela.length - 1].peso_kg) - toNumber(janela[0].peso_kg)
    );
  }, [pesos]);

  const chartData = useMemo(
    () =>
      pesos.map((p) => ({
        data: p.data.slice(0, 10),
        valor: toNumber(p.peso_kg),
      })),
    [pesos],
  );

  // Domínio do eixo Y incluindo a meta — senão a linha de referência fica
  // fora do range automático dos dados e some do gráfico.
  const yDomain = useMemo((): [number, number] | ["auto", "auto"] => {
    if (chartData.length === 0) {
      return ["auto", "auto"];
    }

    const valores = chartData.map((p) => p.valor);
    if (metaKg !== null) {
      valores.push(metaKg);
    }

    return [
      Math.floor(Math.min(...valores) - 1),
      Math.ceil(Math.max(...valores) + 1),
    ];
  }, [chartData, metaKg]);

  const linhas = useMemo(
    () =>
      pesos
        .map((p, i) => ({
          ...p,
          delta: i > 0 ? toNumber(p.peso_kg) - toNumber(pesos[i - 1].peso_kg) : null,
        }))
        .reverse(),
    [pesos],
  );

  function classificacaoImc(valor: number) {
    if (valor < 18.5) return "abaixo do peso";
    if (valor < 25) return "peso normal";
    if (valor < 30) return "sobrepeso";
    return "obesidade";
  }

  async function handleDelete(peso: SaudePeso) {
    if (!window.confirm(`Excluir a pesagem de ${formatFullDate(peso.data)}?`)) {
      return;
    }

    try {
      await deleteSaudePeso(peso.id);
      appToast.success("Pesagem removida.");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover a pesagem.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando pesagens..." />;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Peso e meta"
        description="Evolução das pesagens em relação à meta. Registre pela manhã, sempre nas mesmas condições."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setMetaSheetOpen(true)}>
              <Target className="size-4" />
              Definir meta
            </Button>
            <Button
              onClick={() => {
                setEditing(null);
                setPesoSheetOpen(true);
              }}
            >
              <Plus className="size-4" />
              Registrar peso
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <SummaryCard
          label="Peso atual"
          value={atual !== null ? formatKg(atual) : "—"}
        />
        <SummaryCard
          label={
            meta?.data_alvo ? `Meta (até ${formatDate(meta.data_alvo)})` : "Meta"
          }
          value={metaKg !== null ? formatKg(metaKg) : "—"}
        />
        <SummaryCard
          label="Falta para a meta"
          value={
            falta === null ? "—" : falta > 0 ? formatKg(falta) : "Atingida 🎉"
          }
          accentClass={
            falta !== null ? (falta > 0 ? "text-amber-600" : "text-emerald-600") : undefined
          }
        />
        <SummaryCard
          label="Variação 30 dias"
          value={variacao30 !== null ? formatDeltaKg(variacao30) : "—"}
          accentClass={
            variacao30 !== null
              ? variacao30 < 0
                ? "text-emerald-600"
                : variacao30 > 0
                  ? "text-red-600"
                  : undefined
              : undefined
          }
        />
        <SummaryCard
          label="IMC"
          value={
            imc !== null
              ? `${imc.toFixed(1).replace(".", ",")} · ${classificacaoImc(imc)}`
              : "—"
          }
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Evolução do peso</CardTitle>
          <CardDescription>
            {metaKg !== null
              ? `A linha tracejada marca a meta de ${formatKg(metaKg)}.`
              : "Defina uma meta para ver a linha de referência no gráfico."}
          </CardDescription>
        </CardHeader>
        <CardContent className="h-72">
          {chartData.length < 2 ? (
            <p className="flex h-full items-center justify-center text-sm text-muted-foreground">
              Registre pelo menos duas pesagens para ver o gráfico.
            </p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <LineChart
                data={chartData}
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
                  domain={yDomain}
                  tickLine={false}
                  axisLine={false}
                  width={44}
                  fontSize={12}
                />
                <RechartsTooltip
                  formatter={(value) => [formatKg(Number(value)), "Peso"]}
                  labelFormatter={(label) => formatFullDate(String(label))}
                />
                <Line
                  type="monotone"
                  dataKey="valor"
                  stroke="#10b981"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                />
                {metaKg !== null ? (
                  <ReferenceLine y={metaKg} stroke="#f59e0b" strokeDasharray="6 4" />
                ) : null}
              </LineChart>
            </ResponsiveContainer>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Histórico</CardTitle>
          <CardDescription>{pesos.length} pesagem(ns) registrada(s).</CardDescription>
        </CardHeader>
        <CardContent>
          {linhas.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhuma pesagem ainda. Clique em “Registrar peso”.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Peso</TableHead>
                  <TableHead>Variação</TableHead>
                  <TableHead>Observação</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {linhas.map((peso) => (
                  <TableRow key={peso.id}>
                    <TableCell className="tabular-nums">
                      {formatFullDate(peso.data)}
                    </TableCell>
                    <TableCell className="font-medium tabular-nums">
                      {formatKg(toNumber(peso.peso_kg))}
                    </TableCell>
                    <TableCell
                      className={cn(
                        "tabular-nums",
                        peso.delta !== null && peso.delta < 0 && "text-emerald-600",
                        peso.delta !== null && peso.delta > 0 && "text-red-600",
                      )}
                    >
                      {peso.delta !== null ? formatDeltaKg(peso.delta) : "—"}
                    </TableCell>
                    <TableCell className="max-w-48 truncate text-muted-foreground">
                      {peso.observacao ?? "—"}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            setEditing(peso);
                            setPesoSheetOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(peso)}
                        >
                          <Trash2 className="size-4 text-red-600" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <PesoSheet
        open={pesoSheetOpen}
        onOpenChange={setPesoSheetOpen}
        editing={editing}
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
