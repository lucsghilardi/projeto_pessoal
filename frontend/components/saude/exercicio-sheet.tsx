"use client";

import { useEffect, useState } from "react";

import { appToast } from "@/lib/toast";
import { createSaudeExercicio, updateSaudeExercicio } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeExercicio } from "@/types/Saude";
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
  /** Treino que recebe o exercício novo (aba ativa na página). */
  treinoId: number | null;
  editing: SaudeExercicio | null;
  onSaved: () => void;
};

export function ExercicioSheet({ open, onOpenChange, treinoId, editing, onSaved }: Props) {
  const [nome, setNome] = useState("");
  const [series, setSeries] = useState("");
  const [repeticoes, setRepeticoes] = useState("");
  const [carga, setCarga] = useState("");
  const [observacoes, setObservacoes] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setNome(editing?.nome ?? "");
    setSeries(editing?.series != null ? String(editing.series) : "");
    setRepeticoes(editing?.repeticoes ?? "");
    setCarga(editing?.carga ?? "");
    setObservacoes(editing?.observacoes ?? "");
    setFormError(null);
  }, [open, editing]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!nome.trim()) {
      setFormError("Informe o nome do exercício.");
      return;
    }

    const payload = {
      nome: nome.trim(),
      series: series.trim() ? Number(series) : null,
      repeticoes: repeticoes.trim() || null,
      carga: carga.trim() || null,
      observacoes: observacoes.trim() || null,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeExercicio(editing.id, payload);
        appToast.success("Exercício atualizado.");
      } else {
        if (treinoId === null) {
          setFormError("Selecione um treino antes de adicionar exercícios.");
          return;
        }
        await createSaudeExercicio({ ...payload, treino_id: treinoId });
        appToast.success("Exercício adicionado.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar o exercício.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{editing ? "Editar exercício" : "Novo exercício"}</SheetTitle>
          <SheetDescription>
            Séries, repetições e carga são texto livre — anote do seu jeito.
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <Field>
              <FieldLabel htmlFor="ex-nome">Exercício</FieldLabel>
              <Input
                id="ex-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Ex.: Supino reto"
                required
              />
            </Field>

            <div className="grid grid-cols-3 gap-3">
              <Field>
                <FieldLabel htmlFor="ex-series">Séries</FieldLabel>
                <Input
                  id="ex-series"
                  type="number"
                  min={1}
                  max={50}
                  value={series}
                  onChange={(e) => setSeries(e.target.value)}
                  placeholder="4"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="ex-repeticoes">Repetições</FieldLabel>
                <Input
                  id="ex-repeticoes"
                  value={repeticoes}
                  onChange={(e) => setRepeticoes(e.target.value)}
                  placeholder="8-12"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="ex-carga">Carga</FieldLabel>
                <Input
                  id="ex-carga"
                  value={carga}
                  onChange={(e) => setCarga(e.target.value)}
                  placeholder="24 kg"
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="ex-observacoes">Observações</FieldLabel>
              <Input
                id="ex-observacoes"
                value={observacoes}
                onChange={(e) => setObservacoes(e.target.value)}
                placeholder="Ex.: pegada fechada, cadência lenta"
              />
            </Field>

            <FieldError>{formError}</FieldError>
          </FieldGroup>

          <SheetFooter className="px-0">
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner data-icon="inline-start" /> : null}
              {editing ? "Salvar alterações" : "Adicionar exercício"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
