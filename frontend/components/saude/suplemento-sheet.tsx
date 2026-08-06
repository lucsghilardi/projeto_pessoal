"use client";

import { useEffect, useState } from "react";
import { ArrowLeft, Pencil, Plus, Trash2 } from "lucide-react";

import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import {
  createSaudeSuplemento,
  deleteSaudeSuplemento,
  getSaudeSuplementos,
  updateSaudeSuplemento,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeSuplemento } from "@/types/Saude";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Avisa a página para recarregar o overview após qualquer alteração. */
  onChanged: () => void;
};

/** Gerencia o cadastro de suplementos (lista + formulário) num Sheet. */
export function SuplementoSheet({ open, onOpenChange, onChanged }: Props) {
  const [suplementos, setSuplementos] = useState<SaudeSuplemento[]>([]);
  const [loading, setLoading] = useState(false);
  const [view, setView] = useState<"lista" | "form">("lista");
  const [editing, setEditing] = useState<SaudeSuplemento | null>(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const [nome, setNome] = useState("");
  const [marca, setMarca] = useState("");
  const [dose, setDose] = useState("");
  const [horario, setHorario] = useState("08:00");
  const [instrucao, setInstrucao] = useState("");
  const [observacoes, setObservacoes] = useState("");
  const [ativo, setAtivo] = useState(true);

  useEffect(() => {
    if (!open) {
      return;
    }

    setView("lista");
    let mounted = true;
    setLoading(true);

    (async () => {
      try {
        const data = await getSaudeSuplementos();
        if (mounted) {
          setSuplementos(data);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar os suplementos.",
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
  }, [open]);

  async function reload() {
    setSuplementos(await getSaudeSuplementos());
  }

  function openCreate() {
    setEditing(null);
    setNome("");
    setMarca("");
    setDose("");
    setHorario("08:00");
    setInstrucao("");
    setObservacoes("");
    setAtivo(true);
    setFormError(null);
    setView("form");
  }

  function openEdit(suplemento: SaudeSuplemento) {
    setEditing(suplemento);
    setNome(suplemento.nome);
    setMarca(suplemento.marca ?? "");
    setDose(suplemento.dose ?? "");
    setHorario(suplemento.horario.slice(0, 5));
    setInstrucao(suplemento.instrucao ?? "");
    setObservacoes(suplemento.observacoes ?? "");
    setAtivo(suplemento.ativo);
    setFormError(null);
    setView("form");
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!nome.trim()) {
      setFormError("Informe o nome do suplemento.");
      return;
    }

    const payload = {
      nome: nome.trim(),
      marca: marca.trim() || null,
      dose: dose.trim() || null,
      horario,
      instrucao: instrucao.trim() || null,
      observacoes: observacoes.trim() || null,
      ativo,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeSuplemento(editing.id, payload);
        appToast.success("Suplemento atualizado.");
      } else {
        await createSaudeSuplemento(payload);
        appToast.success("Suplemento criado.");
      }
      await reload();
      onChanged();
      setView("lista");
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar o suplemento.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleToggleAtivo(suplemento: SaudeSuplemento) {
    try {
      await updateSaudeSuplemento(suplemento.id, {
        nome: suplemento.nome,
        marca: suplemento.marca,
        dose: suplemento.dose,
        horario: suplemento.horario.slice(0, 5),
        instrucao: suplemento.instrucao,
        observacoes: suplemento.observacoes,
        ativo: !suplemento.ativo,
      });
      await reload();
      onChanged();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível atualizar o suplemento.",
      );
    }
  }

  async function handleDelete(suplemento: SaudeSuplemento) {
    if (
      !window.confirm(
        `Excluir "${suplemento.nome}"? O histórico de check-ins dele também será removido.`,
      )
    ) {
      return;
    }

    try {
      await deleteSaudeSuplemento(suplemento.id);
      appToast.success("Suplemento removido.");
      await reload();
      onChanged();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover o suplemento.",
      );
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        {view === "lista" ? (
          <>
            <SheetHeader>
              <SheetTitle>Meus suplementos</SheetTitle>
              <SheetDescription>
                Horários e instruções aparecem no check-in diário. Inativos ficam
                fora da lista do dia.
              </SheetDescription>
            </SheetHeader>

            <div className="space-y-2 px-4 pb-4">
              <Button onClick={openCreate} className="w-full">
                <Plus className="size-4" />
                Novo suplemento
              </Button>

              {loading ? (
                <div className="flex justify-center py-8">
                  <Spinner />
                </div>
              ) : suplementos.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  Nenhum suplemento cadastrado ainda.
                </p>
              ) : (
                suplementos.map((suplemento) => (
                  <div
                    key={suplemento.id}
                    className={cn(
                      "rounded-lg border p-3",
                      !suplemento.ativo && "opacity-60",
                    )}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="text-sm font-medium">
                          <span className="tabular-nums text-muted-foreground">
                            {suplemento.horario.slice(0, 5)}
                          </span>{" "}
                          · {suplemento.nome}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {[suplemento.marca, suplemento.dose, suplemento.instrucao]
                            .filter(Boolean)
                            .join(" · ") || "—"}
                        </p>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          onClick={() => openEdit(suplemento)}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon-sm"
                          onClick={() => handleDelete(suplemento)}
                        >
                          <Trash2 className="size-4 text-red-600" />
                        </Button>
                      </div>
                    </div>
                    <label className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                      <input
                        type="checkbox"
                        checked={suplemento.ativo}
                        onChange={() => handleToggleAtivo(suplemento)}
                        className="size-3.5 accent-emerald-600"
                      />
                      Ativo no check-in diário
                    </label>
                  </div>
                ))
              )}
            </div>
          </>
        ) : (
          <>
            <SheetHeader>
              <SheetTitle>
                {editing ? "Editar suplemento" : "Novo suplemento"}
              </SheetTitle>
              <SheetDescription>
                O horário define a ordem do check-in e o lembrete no WhatsApp.
              </SheetDescription>
            </SheetHeader>

            <form className="px-4 pb-4" onSubmit={handleSubmit}>
              <FieldGroup className="gap-4">
                <Field>
                  <FieldLabel htmlFor="sup-nome">Nome</FieldLabel>
                  <Input
                    id="sup-nome"
                    value={nome}
                    onChange={(e) => setNome(e.target.value)}
                    placeholder="Ex.: Creatina"
                    required
                  />
                </Field>

                <div className="grid grid-cols-2 gap-3">
                  <Field>
                    <FieldLabel htmlFor="sup-horario">Horário</FieldLabel>
                    <Input
                      id="sup-horario"
                      type="time"
                      value={horario}
                      onChange={(e) => setHorario(e.target.value)}
                      required
                    />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="sup-dose">Dose</FieldLabel>
                    <Input
                      id="sup-dose"
                      value={dose}
                      onChange={(e) => setDose(e.target.value)}
                      placeholder="Ex.: 1 cápsula"
                    />
                  </Field>
                </div>

                <Field>
                  <FieldLabel htmlFor="sup-marca">Marca</FieldLabel>
                  <Input
                    id="sup-marca"
                    value={marca}
                    onChange={(e) => setMarca(e.target.value)}
                    placeholder="Opcional"
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="sup-instrucao">Instrução</FieldLabel>
                  <Input
                    id="sup-instrucao"
                    value={instrucao}
                    onChange={(e) => setInstrucao(e.target.value)}
                    placeholder="Ex.: em jejum, junto com o almoço"
                  />
                </Field>

                <Field>
                  <FieldLabel htmlFor="sup-observacoes">Observações</FieldLabel>
                  <textarea
                    id="sup-observacoes"
                    value={observacoes}
                    onChange={(e) => setObservacoes(e.target.value)}
                    placeholder="Ex.: contém cafeína — evitar após as 16h"
                    rows={3}
                    className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px]"
                  />
                </Field>

                <label className="flex items-center gap-3">
                  <input
                    type="checkbox"
                    checked={ativo}
                    onChange={(e) => setAtivo(e.target.checked)}
                    className="size-4 accent-emerald-600"
                  />
                  <span className="text-sm font-medium">
                    Ativo no check-in diário
                  </span>
                </label>

                <FieldError>{formError}</FieldError>
              </FieldGroup>

              <SheetFooter className="flex-row gap-2 px-0">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setView("lista")}
                >
                  <ArrowLeft className="size-4" />
                  Voltar
                </Button>
                <Button type="submit" disabled={saving} className="flex-1">
                  {saving ? <Spinner data-icon="inline-start" /> : null}
                  {editing ? "Salvar alterações" : "Criar suplemento"}
                </Button>
              </SheetFooter>
            </form>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
