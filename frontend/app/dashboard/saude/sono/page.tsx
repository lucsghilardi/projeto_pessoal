"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Moon, Pencil, Plus, Target, Trash2 } from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
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
import { SonoSheet } from "@/components/saude/sono-sheet";
import { useGarminAutoSync } from "@/hooks/use-garmin-auto-sync";
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
import { formatDate, formatDuration, formatFullDate } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import { deleteSaudeSono, getSaudeMeta, getSaudeSonos } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeMeta, SaudeSono, SaudeSonoQualificador } from "@/types/Saude";

/** Janela carregada. 90 dias já mostra sazonalidade sem pesar a tabela. */
const DIAS_JANELA = 90;

const LABEL_QUALIFICADOR: Record<SaudeSonoQualificador, string> = {
  POOR: "Ruim",
  FAIR: "Razoável",
  GOOD: "Bom",
  EXCELLENT: "Excelente",
};

const COR_QUALIFICADOR: Record<SaudeSonoQualificador, string> = {
  POOR: "text-red-600",
  FAIR: "text-amber-600",
  GOOD: "text-emerald-600",
  EXCELLENT: "text-emerald-600",
};

/** "YYYY-MM-DD" de `dias` atrás, no fuso local. */
function diasAtras(dias: number) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** Minutos -> "7h 30m". */
function formatMin(minutos: number) {
  return formatDuration(minutos * 60);
}

function media(valores: number[]) {
  if (valores.length === 0) {
    return null;
  }

  return valores.reduce((soma, v) => soma + v, 0) / valores.length;
}

export default function SonoPage() {
  const [sonos, setSonos] = useState<SaudeSono[]>([]);
  const [meta, setMeta] = useState<SaudeMeta>(null);
  const [loading, setLoading] = useState(true);
  const [sonoSheetOpen, setSonoSheetOpen] = useState(false);
  const [metaSheetOpen, setMetaSheetOpen] = useState(false);
  const [editing, setEditing] = useState<SaudeSono | null>(null);

  const load = useCallback(async () => {
    const [sonosData, metaData] = await Promise.all([
      getSaudeSonos(diasAtras(DIAS_JANELA)),
      getSaudeMeta(),
    ]);

    setSonos(sonosData);
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
            : "Não foi possível carregar as noites.",
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

  // O relógio sincroniza sozinho ao abrir a tela (trava de 10 min no servidor).
  useGarminAutoSync(load);

  const metaMin = meta?.sono_meta_min ?? null;

  const janela = useCallback(
    (dias: number) => {
      const corte = diasAtras(dias);
      return sonos.filter((s) => s.data.slice(0, 10) >= corte);
    },
    [sonos],
  );

  const media7 = useMemo(
    () => media(janela(7).map((s) => s.duracao_min)),
    [janela],
  );
  const media30 = useMemo(
    () => media(janela(30).map((s) => s.duracao_min)),
    [janela],
  );
  const scoreMedio = useMemo(() => {
    const scores = janela(30)
      .map((s) => s.score)
      .filter((s): s is number => s !== null);

    return media(scores);
  }, [janela]);

  const naMeta = useMemo(() => {
    if (metaMin === null) {
      return null;
    }

    const ultimas = janela(30);
    return {
      atingidas: ultimas.filter((s) => s.duracao_min >= metaMin).length,
      total: ultimas.length,
    };
  }, [janela, metaMin]);

  const chartData = useMemo(
    () =>
      sonos.map((s) => {
        const profundo = s.profundo_min ?? 0;
        const leve = s.leve_min ?? 0;
        const rem = s.rem_min ?? 0;

        return {
          data: s.data.slice(0, 10),
          profundo: profundo / 60,
          leve: leve / 60,
          rem: rem / 60,
          // Noite lançada à mão não tem fases: o resto vira uma barra cinza,
          // senão ela sumiria do gráfico apesar de existir.
          resto: Math.max(0, s.duracao_min - profundo - leve - rem) / 60,
        };
      }),
    [sonos],
  );

  const linhas = useMemo(() => [...sonos].reverse(), [sonos]);

  async function handleDelete(sono: SaudeSono) {
    if (!window.confirm(`Excluir a noite de ${formatFullDate(sono.data)}?`)) {
      return;
    }

    try {
      await deleteSaudeSono(sono.id);
      appToast.success("Noite removida.");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover a noite.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando noites..." />;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Sono"
        description="Noites medidas pelo relógio. A data é o dia em que você acordou — a noite de domingo para segunda aparece na segunda."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setMetaSheetOpen(true)}>
              <Target className="size-4" />
              Definir meta
            </Button>
            <Button
              onClick={() => {
                setEditing(null);
                setSonoSheetOpen(true);
              }}
            >
              <Plus className="size-4" />
              Registrar sono
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <SummaryCard
          label="Média 7 dias"
          value={media7 !== null ? formatMin(Math.round(media7)) : "—"}
          accentClass={
            media7 !== null && metaMin !== null
              ? media7 >= metaMin
                ? "text-emerald-600"
                : "text-amber-600"
              : undefined
          }
          icon={<Moon className="size-3.5" />}
        />
        <SummaryCard
          label="Média 30 dias"
          value={media30 !== null ? formatMin(Math.round(media30)) : "—"}
        />
        <SummaryCard
          label="Meta por noite"
          value={metaMin !== null ? formatMin(metaMin) : "—"}
        />
        <SummaryCard
          label="Noites na meta (30d)"
          value={naMeta ? `${naMeta.atingidas} / ${naMeta.total}` : "—"}
        />
        <SummaryCard
          label="Score médio (30d)"
          value={scoreMedio !== null ? String(Math.round(scoreMedio)) : "—"}
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Horas por noite</CardTitle>
          <CardDescription>
            Fases medidas pelo relógio: profundo, leve e REM.
            {metaMin !== null
              ? ` A linha tracejada marca a meta de ${formatMin(metaMin)}.`
              : " Defina uma meta para ver a linha de referência."}
          </CardDescription>
        </CardHeader>
        <CardContent className="h-72">
          {chartData.length === 0 ? (
            <p className="flex h-full items-center justify-center text-sm text-muted-foreground">
              Nenhuma noite registrada ainda.
            </p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
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
                  interval="preserveStartEnd"
                  minTickGap={24}
                />
                <YAxis
                  tickLine={false}
                  axisLine={false}
                  width={32}
                  fontSize={12}
                  tickFormatter={(v) => `${v}h`}
                />
                <RechartsTooltip
                  formatter={(value, name) => [
                    formatMin(Math.round(Number(value) * 60)),
                    name === "profundo"
                      ? "Profundo"
                      : name === "leve"
                        ? "Leve"
                        : name === "rem"
                          ? "REM"
                          : "Sem detalhe",
                  ]}
                  labelFormatter={(label) => formatFullDate(String(label))}
                />
                <Bar dataKey="profundo" stackId="sono" fill="#4f46e5" />
                <Bar dataKey="leve" stackId="sono" fill="#818cf8" />
                <Bar dataKey="rem" stackId="sono" fill="#22d3ee" />
                <Bar
                  dataKey="resto"
                  stackId="sono"
                  fill="#cbd5e1"
                  radius={[3, 3, 0, 0]}
                />
                {metaMin !== null ? (
                  <ReferenceLine
                    y={metaMin / 60}
                    stroke="#f59e0b"
                    strokeDasharray="6 4"
                  />
                ) : null}
              </BarChart>
            </ResponsiveContainer>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Histórico</CardTitle>
          <CardDescription>
            Últimos {DIAS_JANELA} dias — {sonos.length} noite(s) registrada(s).
          </CardDescription>
        </CardHeader>
        <CardContent>
          {linhas.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhuma noite ainda. O relógio importa sozinho, ou clique em
              “Registrar sono”.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead className="text-center">Dormiu</TableHead>
                  <TableHead className="text-center">Horário</TableHead>
                  <TableHead className="text-center">Profundo</TableHead>
                  <TableHead className="text-center">REM</TableHead>
                  <TableHead className="text-center">Despertares</TableHead>
                  <TableHead className="text-center">Score</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {linhas.map((sono) => (
                  <TableRow key={sono.id}>
                    <TableCell className="tabular-nums">
                      {formatFullDate(sono.data)}
                      <p className="text-xs text-muted-foreground">
                        {sono.origem === "garmin" ? "Garmin" : "Manual"}
                      </p>
                    </TableCell>
                    <TableCell
                      className={cn(
                        "text-center font-medium tabular-nums",
                        metaMin !== null &&
                          (sono.duracao_min >= metaMin
                            ? "text-emerald-600"
                            : "text-amber-600"),
                      )}
                    >
                      {formatMin(sono.duracao_min)}
                    </TableCell>
                    <TableCell className="text-center tabular-nums text-muted-foreground">
                      {sono.inicio && sono.fim
                        ? `${sono.inicio.slice(0, 5)}–${sono.fim.slice(0, 5)}`
                        : "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {sono.profundo_min !== null
                        ? formatMin(sono.profundo_min)
                        : "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {sono.rem_min !== null ? formatMin(sono.rem_min) : "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {sono.despertares ?? "—"}
                    </TableCell>
                    <TableCell className="text-center tabular-nums">
                      {sono.score !== null ? (
                        <span
                          className={cn(
                            "font-medium",
                            sono.score_qualificador
                              ? COR_QUALIFICADOR[sono.score_qualificador]
                              : undefined,
                          )}
                        >
                          {sono.score}
                          {sono.score_qualificador ? (
                            <span className="ml-1 text-xs font-normal">
                              {LABEL_QUALIFICADOR[sono.score_qualificador]}
                            </span>
                          ) : null}
                        </span>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            setEditing(sono);
                            setSonoSheetOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => handleDelete(sono)}
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

      <SonoSheet
        open={sonoSheetOpen}
        onOpenChange={setSonoSheetOpen}
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
