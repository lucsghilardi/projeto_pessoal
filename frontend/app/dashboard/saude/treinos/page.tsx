"use client";

import { useCallback, useEffect, useState } from "react";
import { ChevronDown, ChevronUp, Pencil, Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { ExercicioSheet } from "@/components/saude/exercicio-sheet";
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
import { Tabs } from "@/components/ui/tabs";
import { formatFullDate } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  deleteSaudeExercicio,
  deleteSaudeSessao,
  getSaudeSessoes,
  getSaudeTreinos,
  reorderSaudeExercicios,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeExercicio, SaudeTreino, SaudeTreinoSessao } from "@/types/Saude";

/** "YYYY-MM-DD" de `dias` atrás, no fuso local. */
function diasAtras(dias: number) {
  const d = new Date();
  d.setDate(d.getDate() - dias);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

export default function TreinosPage() {
  const [treinos, setTreinos] = useState<SaudeTreino[]>([]);
  const [sessoes, setSessoes] = useState<SaudeTreinoSessao[]>([]);
  const [loading, setLoading] = useState(true);
  const [treinoAtivo, setTreinoAtivo] = useState("");
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editing, setEditing] = useState<SaudeExercicio | null>(null);

  const load = useCallback(async () => {
    const [treinosData, sessoesData] = await Promise.all([
      getSaudeTreinos(),
      getSaudeSessoes(diasAtras(60)),
    ]);

    setTreinos(treinosData);
    setSessoes(sessoesData);
    setTreinoAtivo((atual) =>
      treinosData.some((t) => String(t.id) === atual)
        ? atual
        : String(treinosData[0]?.id ?? ""),
    );
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
            : "Não foi possível carregar os treinos.",
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

  const treinoSelecionado =
    treinos.find((t) => String(t.id) === treinoAtivo) ?? null;

  function openCreate() {
    setEditing(null);
    setSheetOpen(true);
  }

  function openEdit(exercicio: SaudeExercicio) {
    setEditing(exercicio);
    setSheetOpen(true);
  }

  async function handleDelete(exercicio: SaudeExercicio) {
    if (!window.confirm(`Excluir "${exercicio.nome}" da ficha?`)) {
      return;
    }

    try {
      await deleteSaudeExercicio(exercicio.id);
      appToast.success("Exercício removido.");
      await load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover o exercício.",
      );
    }
  }

  async function mover(exercicio: SaudeExercicio, delta: -1 | 1) {
    if (!treinoSelecionado) {
      return;
    }

    const ids = treinoSelecionado.exercicios.map((e) => e.id);
    const idx = ids.indexOf(exercicio.id);
    const alvo = idx + delta;
    if (alvo < 0 || alvo >= ids.length) {
      return;
    }

    [ids[idx], ids[alvo]] = [ids[alvo], ids[idx]];

    // Otimista: reordena localmente e persiste em seguida.
    setTreinos((prev) =>
      prev.map((t) =>
        t.id !== treinoSelecionado.id
          ? t
          : {
              ...t,
              exercicios: ids
                .map((id) => t.exercicios.find((e) => e.id === id))
                .filter((e): e is SaudeExercicio => e !== undefined)
                .map((e, posicao) => ({ ...e, posicao })),
            },
      ),
    );

    try {
      await reorderSaudeExercicios(treinoSelecionado.id, ids);
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível reordenar os exercícios.",
      );
      await load();
    }
  }

  async function handleDeleteSessao(sessao: SaudeTreinoSessao) {
    if (!window.confirm(`Remover o treino de ${formatFullDate(sessao.data)}?`)) {
      return;
    }

    try {
      await deleteSaudeSessao(sessao.id);
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
    return <DashboardPageLoader label="Carregando treinos..." />;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Treinos"
        description="Fichas A e B com séries, repetições e carga atual — e o histórico dos dias treinados."
        actions={
          <Button onClick={openCreate} disabled={!treinoSelecionado}>
            <Plus className="size-4" />
            Novo exercício
          </Button>
        }
      />

      {treinos.length === 0 ? (
        <Card>
          <CardContent>
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum treino cadastrado.
            </p>
          </CardContent>
        </Card>
      ) : (
        <>
          <Tabs
            value={treinoAtivo}
            onValueChange={setTreinoAtivo}
            items={treinos.map((t) => ({ value: String(t.id), label: t.nome }))}
          />

          <Card>
            <CardHeader>
              <CardTitle>{treinoSelecionado?.nome}</CardTitle>
              <CardDescription>
                {treinoSelecionado?.exercicios.length ?? 0} exercício(s) na ficha.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {!treinoSelecionado || treinoSelecionado.exercicios.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  Nenhum exercício ainda. Clique em “Novo exercício”.
                </p>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-20">Ordem</TableHead>
                      <TableHead>Exercício</TableHead>
                      <TableHead className="text-center">Séries</TableHead>
                      <TableHead className="text-center">Repetições</TableHead>
                      <TableHead>Carga atual</TableHead>
                      <TableHead className="text-right">Ações</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {treinoSelecionado.exercicios.map((exercicio, idx) => (
                      <TableRow key={exercicio.id}>
                        <TableCell>
                          <div className="flex gap-0.5">
                            <Button
                              variant="ghost"
                              size="icon-xs"
                              disabled={idx === 0}
                              onClick={() => mover(exercicio, -1)}
                            >
                              <ChevronUp className="size-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon-xs"
                              disabled={idx === treinoSelecionado.exercicios.length - 1}
                              onClick={() => mover(exercicio, 1)}
                            >
                              <ChevronDown className="size-4" />
                            </Button>
                          </div>
                        </TableCell>
                        <TableCell>
                          <p className="font-medium">{exercicio.nome}</p>
                          {exercicio.observacoes ? (
                            <p className="text-xs text-muted-foreground">
                              {exercicio.observacoes}
                            </p>
                          ) : null}
                        </TableCell>
                        <TableCell className="text-center tabular-nums">
                          {exercicio.series ?? "—"}
                        </TableCell>
                        <TableCell className="text-center tabular-nums">
                          {exercicio.repeticoes ?? "—"}
                        </TableCell>
                        <TableCell>{exercicio.carga ?? "—"}</TableCell>
                        <TableCell className="text-right">
                          <div className="flex justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => openEdit(exercicio)}
                            >
                              <Pencil className="size-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => handleDelete(exercicio)}
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
        </>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Histórico de treinos</CardTitle>
          <CardDescription>
            Últimos 60 dias — marque o treino do dia na tela de check-in.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {sessoes.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum treino registrado ainda.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Data</TableHead>
                  <TableHead>Treino</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sessoes.map((sessao) => (
                  <TableRow key={sessao.id}>
                    <TableCell className="tabular-nums">
                      {formatFullDate(sessao.data)}
                    </TableCell>
                    <TableCell className="font-medium">
                      {sessao.treino?.nome ?? "—"}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => handleDeleteSessao(sessao)}
                      >
                        <Trash2 className="size-4 text-red-600" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <ExercicioSheet
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        treinoId={treinoSelecionado?.id ?? null}
        editing={editing}
        onSaved={load}
      />
    </div>
  );
}
