"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Heart, Pencil, Plus, RefreshCw, Trash2 } from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { useGarminAutoSync } from "@/hooks/use-garmin-auto-sync";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { CardioSheet, MODALIDADES } from "@/components/saude/cardio-sheet";
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
import { formatDate, formatFullDate, toNumber, todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  deleteSaudeCardio,
  getSaudeCardio,
  getSaudeCardioResumo,
  getSaudeGarminStatus,
  getSaudeTreinos,
  sincronizarSaudeGarmin,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeCardioModalidade,
  SaudeCardioResumo,
  SaudeCardioSessao,
  SaudeGarminStatus,
  SaudeTreino,
} from "@/types/Saude";

/** Meta semanal de minutos de exercício (recomendação da OMS, e a meta do relógio). */
const META_SEMANAL_MIN = 150;

const LABEL_MODALIDADE: Record<SaudeCardioModalidade, string> = Object.fromEntries(
  MODALIDADES.map((m) => [m.value, m.label]),
) as Record<SaudeCardioModalidade, string>;

/** "YYYY-MM-DD" de `dias` atrás, no fuso local. */
function diasAtras(dias: number) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** 3.15 -> "3,15 km" */
function formatKm(value: number) {
  return `${value.toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} km`;
}

/** Ritmo médio em min/km, só faz sentido com distância. */
function formatRitmo(minutos: number, km: number) {
  if (km <= 0) {
    return "—";
  }
  const minPorKm = minutos / km;
  const min = Math.floor(minPorKm);
  const seg = Math.round((minPorKm - min) * 60);

  return `${min}:${String(seg).padStart(2, "0")}/km`;
}

export default function CardioPage() {
  const [sessoes, setSessoes] = useState<SaudeCardioSessao[]>([]);
  const [treinos, setTreinos] = useState<SaudeTreino[]>([]);
  const [resumo, setResumo] = useState<SaudeCardioResumo | null>(null);
  const [garmin, setGarmin] = useState<SaudeGarminStatus | null>(null);
  const [loading, setLoading] = useState(true);
  const [sincronizando, setSincronizando] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editing, setEditing] = useState<SaudeCardioSessao | null>(null);

  const load = useCallback(async () => {
    const [sessoesData, treinosData, resumoData, garminData] = await Promise.all([
      getSaudeCardio(diasAtras(60)),
      getSaudeTreinos(),
      getSaudeCardioResumo(diasAtras(6), todayISO()),
      // A integração é opcional: a página funciona sem ela.
      getSaudeGarminStatus().catch(() => null),
    ]);

    setSessoes(sessoesData);
    setTreinos(treinosData);
    setResumo(resumoData);
    setGarmin(garminData);
  }, []);

  // Puxa as atividades novas do relógio ao abrir a tela (trava no servidor).
  useGarminAutoSync(load);

  async function sincronizarGarmin() {
    setSincronizando(true);
    try {
      const { cardio, treinos: forca } = await sincronizarSaudeGarmin();
      appToast.success(
        cardio + forca > 0
          ? `${cardio} cardio(s) e ${forca} treino(s) importados.`
          : "Tudo já estava sincronizado.",
      );
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível sincronizar com o Garmin.",
      );
    } finally {
      setSincronizando(false);
    }
  }

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        await load();
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar os cardios.",
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

  // Minutos por dia nos últimos 30 dias, com os dias vazios zerados para o
  // gráfico não "encostar" sessões distantes uma na outra.
  const chartData = useMemo(() => {
    const porDia = new Map<string, number>();
    for (const sessao of sessoes) {
      const dia = sessao.data.slice(0, 10);
      porDia.set(dia, (porDia.get(dia) ?? 0) + sessao.duracao_min);
    }

    const dias: Array<{ data: string; minutos: number }> = [];
    for (let i = 29; i >= 0; i--) {
      const dia = diasAtras(i);
      dias.push({ data: dia, minutos: porDia.get(dia) ?? 0 });
    }

    return dias;
  }, [sessoes]);

  async function handleDelete(sessao: SaudeCardioSessao) {
    if (!window.confirm(`Excluir o cardio de ${formatFullDate(sessao.data)}?`)) {
      return;
    }

    try {
      await deleteSaudeCardio(sessao.id);
      appToast.success("Sessão removida.");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover a sessão.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando cardios..." />;
  }

  const minutosSemana = resumo?.minutos ?? 0;

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Cardio"
        description="Sessões de cardio — as do fim do treino A/B e as isoladas. Alvo do fácil: Z2, 127–145 bpm."
        actions={
          <div className="flex flex-wrap gap-2">
            {garmin?.configurado ? (
              <Button
                variant="outline"
                disabled={sincronizando}
                onClick={sincronizarGarmin}
                title={
                  garmin.status.autenticado
                    ? `Conectado como ${garmin.status.nome ?? "—"}`
                    : "Os tokens do Garmin expiraram — refaça o login no sidecar."
                }
              >
                {sincronizando ? (
                  <Spinner data-icon="inline-start" />
                ) : (
                  <RefreshCw className="size-4" />
                )}
                Sincronizar Garmin
              </Button>
            ) : null}
            <Button
              onClick={() => {
                setEditing(null);
                setSheetOpen(true);
              }}
            >
              <Plus className="size-4" />
              Registrar cardio
            </Button>
          </div>
        }
      />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard
          label="Minutos na semana"
          value={`${minutosSemana} / ${META_SEMANAL_MIN} min`}
          accentClass={
            minutosSemana >= META_SEMANAL_MIN ? "text-emerald-600" : "text-amber-600"
          }
          icon={<Heart className="size-3.5" />}
        />
        <SummaryCard label="Sessões na semana" value={String(resumo?.sessoes ?? 0)} />
        <SummaryCard
          label="Distância na semana"
          value={resumo?.distancia_km ? formatKm(resumo.distancia_km) : "—"}
        />
        <SummaryCard
          label="Calorias na semana"
          value={resumo?.calorias ? `${resumo.calorias} kcal` : "—"}
        />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Minutos por dia</CardTitle>
          <CardDescription>
            Últimos 30 dias. Consistência importa mais que a sessão heroica isolada.
          </CardDescription>
        </CardHeader>
        <CardContent className="h-56">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
              <XAxis
                dataKey="data"
                tickFormatter={formatDate}
                tickLine={false}
                axisLine={false}
                fontSize={12}
                interval={4}
              />
              <YAxis tickLine={false} axisLine={false} width={32} fontSize={12} />
              <RechartsTooltip
                formatter={(value) => [`${Number(value)} min`, "Cardio"]}
                labelFormatter={(label) => formatFullDate(String(label))}
              />
              <Bar dataKey="minutos" fill="#10b981" radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Histórico</CardTitle>
          <CardDescription>
            Últimos 60 dias — {sessoes.length} sessão(ões) registrada(s).
          </CardDescription>
        </CardHeader>
        <CardContent>
          {sessoes.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum cardio ainda. Clique em “Registrar cardio”.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Modalidade</TableHead>
                  <TableHead className="text-center">Duração</TableHead>
                  <TableHead className="text-center">Distância</TableHead>
                  <TableHead className="text-center">Ritmo</TableHead>
                  <TableHead className="text-center">FC média</TableHead>
                  <TableHead className="text-center">kcal</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sessoes.map((sessao) => {
                  const km = sessao.distancia_km ? toNumber(sessao.distancia_km) : 0;

                  return (
                    <TableRow key={sessao.id}>
                      <TableCell className="tabular-nums">
                        {formatFullDate(sessao.data)}
                        {sessao.horario ? (
                          <span className="text-muted-foreground">
                            {" "}
                            {sessao.horario.slice(0, 5)}
                          </span>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        <p className="font-medium">
                          {LABEL_MODALIDADE[sessao.modalidade] ?? sessao.modalidade}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {sessao.treino?.nome ?? "Avulso"}
                          {sessao.origem === "garmin" ? " · Garmin" : ""}
                        </p>
                      </TableCell>
                      <TableCell className="text-center tabular-nums">
                        {sessao.duracao_min} min
                      </TableCell>
                      <TableCell className="text-center tabular-nums">
                        {km > 0 ? formatKm(km) : "—"}
                      </TableCell>
                      <TableCell className="text-center tabular-nums">
                        {formatRitmo(sessao.duracao_min, km)}
                      </TableCell>
                      <TableCell className="text-center tabular-nums">
                        {sessao.fc_media ?? "—"}
                      </TableCell>
                      <TableCell className="text-center tabular-nums">
                        {sessao.calorias ?? "—"}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => {
                              setEditing(sessao);
                              setSheetOpen(true);
                            }}
                          >
                            <Pencil className="size-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => handleDelete(sessao)}
                          >
                            <Trash2 className="size-4 text-red-600" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <CardioSheet
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        editing={editing}
        treinos={treinos}
        onSaved={load}
      />
    </div>
  );
}
