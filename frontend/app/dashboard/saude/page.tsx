"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowRight, Dumbbell, Footprints, Scale, Settings2 } from "lucide-react";
import { Line, LineChart, ResponsiveContainer } from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { useGarminAutoSync } from "@/hooks/use-garmin-auto-sync";
import { StreakCard } from "@/components/saude/streak-card";
import { SuplementoSheet } from "@/components/saude/suplemento-sheet";
import { fireConfetti } from "@/components/tarefas/confetti";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { formatFullDate, formatKg, todayISO, toNumber } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import {
  checkSaudeSuplemento,
  createSaudePeso,
  deleteSaudeSessao,
  getSaudeOverview,
  registrarSaudeSessao,
  uncheckSaudeSuplemento,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeOverview,
  SaudeSuplementoDia,
  SaudeTreinoResumo,
} from "@/types/Saude";

const PERIODOS: Array<{ titulo: string; cobre: (hora: string) => boolean }> = [
  { titulo: "Manhã", cobre: (hora) => hora < "12" },
  { titulo: "Tarde", cobre: (hora) => hora >= "12" && hora < "18" },
  { titulo: "Noite", cobre: (hora) => hora >= "18" },
];

/** "YYYY-MM-DD" de `dias` atrás, no fuso local. */
function diasAtras(dias: number) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

export default function SaudePage() {
  const [overview, setOverview] = useState<SaudeOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [salvandoCheckin, setSalvandoCheckin] = useState<Set<number>>(new Set());
  const [salvandoSessao, setSalvandoSessao] = useState(false);
  const [pesoRapido, setPesoRapido] = useState("");
  const [salvandoPeso, setSalvandoPeso] = useState(false);

  const load = useCallback(async () => {
    try {
      setOverview(await getSaudeOverview(todayISO()));
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível carregar o módulo de saúde.",
      );
    }
  }, []);

  // Puxa as atividades novas do relógio ao abrir a tela (trava no servidor).
  useGarminAutoSync(load);

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const data = await getSaudeOverview(todayISO());
        if (mounted) {
          setOverview(data);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar o módulo de saúde.",
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
  }, []);

  const grupos = useMemo(() => {
    if (!overview) {
      return [];
    }

    return PERIODOS.map((periodo) => ({
      titulo: periodo.titulo,
      itens: overview.suplementos.filter((s) => periodo.cobre(s.horario.slice(0, 2))),
    })).filter((grupo) => grupo.itens.length > 0);
  }, [overview]);

  const sessoesSemana = useMemo(() => {
    if (!overview) {
      return 0;
    }

    const corte = diasAtras(6);
    return overview.sessoes_recentes.filter((s) => s.data.slice(0, 10) >= corte).length;
  }, [overview]);

  const sparkline = useMemo(
    () =>
      (overview?.peso.recentes ?? []).map((p) => ({
        data: p.data,
        valor: toNumber(p.peso_kg),
      })),
    [overview],
  );

  function aplicaTomado(id: number, tomado: boolean) {
    setOverview((prev) =>
      prev
        ? {
            ...prev,
            suplementos: prev.suplementos.map((s) =>
              s.id === id ? { ...s, tomado } : s,
            ),
            progresso: {
              ...prev.progresso,
              tomados: Math.max(
                0,
                Math.min(
                  prev.progresso.total,
                  prev.progresso.tomados + (tomado ? 1 : -1),
                ),
              ),
            },
          }
        : prev,
    );
  }

  async function toggleSuplemento(suplemento: SaudeSuplementoDia) {
    if (!overview || salvandoCheckin.has(suplemento.id)) {
      return;
    }

    const marcar = !suplemento.tomado;
    setSalvandoCheckin((prev) => new Set(prev).add(suplemento.id));
    aplicaTomado(suplemento.id, marcar); // otimista; reverte em erro

    try {
      const result = marcar
        ? await checkSaudeSuplemento(suplemento.id, todayISO())
        : await uncheckSaudeSuplemento(suplemento.id, todayISO());

      setOverview((prev) =>
        prev ? { ...prev, progresso: result.progresso, streak: result.streak } : prev,
      );

      if (
        marcar &&
        result.progresso.total > 0 &&
        result.progresso.tomados === result.progresso.total
      ) {
        fireConfetti();
      }
    } catch (error) {
      aplicaTomado(suplemento.id, !marcar);
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível atualizar o check-in.",
      );
    } finally {
      setSalvandoCheckin((prev) => {
        const next = new Set(prev);
        next.delete(suplemento.id);
        return next;
      });
    }
  }

  async function marcarTreino(treino: SaudeTreinoResumo) {
    if (!overview || salvandoSessao) {
      return;
    }

    setSalvandoSessao(true);
    try {
      if (overview.sessao_hoje?.treino_id === treino.id) {
        await deleteSaudeSessao(overview.sessao_hoje.id);
        appToast.info("Treino de hoje desmarcado.");
      } else {
        await registrarSaudeSessao(treino.id, todayISO());
        appToast.success(`${treino.nome} registrado!`);
      }
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível registrar o treino.",
      );
    } finally {
      setSalvandoSessao(false);
    }
  }

  async function salvarPesoRapido(event: React.FormEvent) {
    event.preventDefault();

    const valor = Number(pesoRapido.replace(",", "."));
    if (!pesoRapido.trim() || Number.isNaN(valor)) {
      return;
    }

    setSalvandoPeso(true);
    try {
      await createSaudePeso({ data: todayISO(), peso_kg: valor });
      appToast.success("Peso de hoje registrado.");
      setPesoRapido("");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível registrar o peso.",
      );
    } finally {
      setSalvandoPeso(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando saúde..." />;
  }

  if (!overview) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        Não foi possível carregar o módulo de saúde. Recarregue a página.
      </p>
    );
  }

  const ultimo = overview.peso.ultimo;
  const meta = overview.peso.meta;
  const falta =
    ultimo && meta?.peso_meta_kg
      ? toNumber(ultimo.peso_kg) - toNumber(meta.peso_meta_kg)
      : null;

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Saúde"
        description={`Check-in de ${formatFullDate(overview.data)} — suplementos, treino e peso do dia.`}
        actions={
          <Button variant="outline" onClick={() => setSheetOpen(true)}>
            <Settings2 className="size-4" />
            Gerenciar suplementos
          </Button>
        }
      />

      <StreakCard progresso={overview.progresso} streak={overview.streak} />

      <Card>
        <CardHeader>
          <CardTitle>Suplementos de hoje</CardTitle>
          <CardDescription>
            Toque num item para marcar como tomado. O lembrete do WhatsApp cobra
            só o que ficar pendente após o horário.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {overview.suplementos.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              Nenhum suplemento ativo. Use “Gerenciar suplementos” para cadastrar.
            </p>
          ) : (
            grupos.map((grupo) => (
              <div key={grupo.titulo} className="space-y-2">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  {grupo.titulo}
                </p>
                {grupo.itens.map((suplemento) => (
                  <button
                    key={suplemento.id}
                    type="button"
                    onClick={() => toggleSuplemento(suplemento)}
                    disabled={salvandoCheckin.has(suplemento.id)}
                    className={cn(
                      "flex w-full items-center gap-3 rounded-lg border p-3 text-left transition-colors",
                      suplemento.tomado
                        ? "border-emerald-200 bg-emerald-50/60"
                        : "hover:bg-muted/50",
                    )}
                  >
                    <input
                      type="checkbox"
                      checked={suplemento.tomado}
                      readOnly
                      tabIndex={-1}
                      className="pointer-events-none size-5 shrink-0 accent-emerald-600"
                    />
                    <span className="w-11 shrink-0 text-sm font-semibold tabular-nums text-muted-foreground">
                      {suplemento.horario.slice(0, 5)}
                    </span>
                    <span className="min-w-0 flex-1">
                      <span
                        className={cn(
                          "block truncate text-sm font-medium",
                          suplemento.tomado && "text-muted-foreground line-through",
                        )}
                      >
                        {suplemento.nome}
                        {suplemento.dose ? ` · ${suplemento.dose}` : ""}
                      </span>
                      {suplemento.instrucao ? (
                        <span className="block truncate text-xs text-muted-foreground">
                          {suplemento.instrucao}
                        </span>
                      ) : null}
                    </span>
                  </button>
                ))}
              </div>
            ))
          )}
        </CardContent>
      </Card>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Dumbbell className="size-4 text-emerald-600" />
              Treino de hoje
            </CardTitle>
            <CardDescription>
              {overview.sessao_hoje
                ? `Hoje: ${overview.sessao_hoje.treino?.nome ?? "treino registrado"}. Toque de novo para desfazer.`
                : "Marque qual ficha você fez hoje."}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {/* Só musculação: ficha de cardio é prescrição e se registra em
                  /saude/cardio — marcá-la aqui sobrescreveria o treino do dia. */}
              {overview.treinos
                .filter((treino) => treino.tipo !== "cardio")
                .map((treino) => (
                  <Button
                    key={treino.id}
                    variant={
                      overview.sessao_hoje?.treino_id === treino.id
                        ? "default"
                        : "outline"
                    }
                    disabled={salvandoSessao}
                    onClick={() => marcarTreino(treino)}
                  >
                    {treino.nome}
                  </Button>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
              {sessoesSemana} {sessoesSemana === 1 ? "treino" : "treinos"} nos
              últimos 7 dias.
            </p>
            <Link
              href="/dashboard/saude/treinos"
              className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline"
            >
              Ver fichas A/B
              <ArrowRight className="size-3.5" />
            </Link>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Footprints className="size-4 text-emerald-600" />
              Cardio de hoje
            </CardTitle>
            <CardDescription>
              {overview.cardio_hoje.length > 0
                ? `${overview.cardio_hoje.reduce((total, c) => total + c.duracao_min, 0)} min hoje, em ${overview.cardio_hoje.length} sessão(ões).`
                : "Nenhum cardio registrado hoje."}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {overview.cardio_hoje.length > 0 ? (
              <ul className="space-y-1 text-sm">
                {overview.cardio_hoje.map((cardio) => (
                  <li key={cardio.id} className="flex justify-between gap-2">
                    <span className="truncate">
                      {cardio.horario ? `${cardio.horario.slice(0, 5)} · ` : ""}
                      {cardio.treino?.nome ?? "Avulso"}
                    </span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {cardio.duracao_min} min
                      {cardio.fc_media ? ` · ${cardio.fc_media} bpm` : ""}
                    </span>
                  </li>
                ))}
              </ul>
            ) : null}
            <p className="text-xs text-muted-foreground">
              {overview.cardio_semana.minutos} de 150 min na semana.
            </p>
            <Link
              href="/dashboard/saude/cardio"
              className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline"
            >
              Registrar cardio
              <ArrowRight className="size-3.5" />
            </Link>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Scale className="size-4 text-emerald-600" />
              Peso
            </CardTitle>
            <CardDescription>
              {ultimo
                ? `Última pesagem em ${formatFullDate(ultimo.data)}.`
                : "Nenhuma pesagem registrada ainda."}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-end justify-between gap-3">
              <div className="min-w-0">
                <p className="text-2xl font-semibold tabular-nums">
                  {ultimo ? formatKg(toNumber(ultimo.peso_kg)) : "—"}
                </p>
                <p className="text-xs text-muted-foreground">
                  {meta?.peso_meta_kg
                    ? falta !== null && falta > 0
                      ? `Meta ${formatKg(toNumber(meta.peso_meta_kg))} — faltam ${formatKg(falta)}`
                      : `Meta ${formatKg(toNumber(meta.peso_meta_kg))} atingida! 🎉`
                    : "Defina sua meta na página de peso."}
                </p>
              </div>
              {sparkline.length >= 2 ? (
                <div className="h-12 w-28 shrink-0">
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={sparkline}>
                      <Line
                        type="monotone"
                        dataKey="valor"
                        stroke="#10b981"
                        strokeWidth={2}
                        dot={false}
                      />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              ) : null}
            </div>

            <form onSubmit={salvarPesoRapido} className="flex gap-2">
              <Input
                type="number"
                step="0.1"
                min={20}
                max={400}
                placeholder="Peso de hoje (kg)"
                value={pesoRapido}
                onChange={(e) => setPesoRapido(e.target.value)}
              />
              <Button type="submit" disabled={salvandoPeso || !pesoRapido.trim()}>
                Registrar
              </Button>
            </form>

            <Link
              href="/dashboard/saude/peso"
              className="inline-flex items-center gap-1 text-sm font-medium text-emerald-700 hover:underline"
            >
              Ver evolução e meta
              <ArrowRight className="size-3.5" />
            </Link>
          </CardContent>
        </Card>
      </div>

      <SuplementoSheet
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        onChanged={load}
      />
    </div>
  );
}
