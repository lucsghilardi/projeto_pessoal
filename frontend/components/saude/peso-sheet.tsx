"use client";

import { useEffect, useState } from "react";

import { todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { createSaudePeso, updateSaudePeso } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudePeso } from "@/types/Saude";
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
  editing: SaudePeso | null;
  onSaved: () => void;
};

export function PesoSheet({ open, onOpenChange, editing, onSaved }: Props) {
  const [data, setData] = useState(todayISO());
  const [peso, setPeso] = useState("");
  const [observacao, setObservacao] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setData(editing ? editing.data.slice(0, 10) : todayISO());
    setPeso(editing ? editing.peso_kg : "");
    setObservacao(editing?.observacao ?? "");
    setFormError(null);
  }, [open, editing]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    const valor = Number(peso.replace(",", "."));
    if (!peso.trim() || Number.isNaN(valor)) {
      setFormError("Informe o peso em kg.");
      return;
    }

    setSaving(true);
    try {
      if (editing) {
        await updateSaudePeso(editing.id, {
          peso_kg: valor,
          observacao: observacao.trim() || null,
        });
        appToast.success("Pesagem atualizada.");
      } else {
        await createSaudePeso({
          data,
          peso_kg: valor,
          observacao: observacao.trim() || null,
        });
        appToast.success("Pesagem registrada.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a pesagem.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{editing ? "Editar pesagem" : "Registrar peso"}</SheetTitle>
          <SheetDescription>
            Uma pesagem por dia — registrar de novo no mesmo dia substitui o valor.
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="peso-data">Data</FieldLabel>
                <Input
                  id="peso-data"
                  type="date"
                  value={data}
                  onChange={(e) => setData(e.target.value)}
                  disabled={editing !== null}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="peso-valor">Peso (kg)</FieldLabel>
                <Input
                  id="peso-valor"
                  type="number"
                  step="0.1"
                  min={20}
                  max={400}
                  value={peso}
                  onChange={(e) => setPeso(e.target.value)}
                  placeholder="92.5"
                  required
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="peso-observacao">Observação</FieldLabel>
              <Input
                id="peso-observacao"
                value={observacao}
                onChange={(e) => setObservacao(e.target.value)}
                placeholder="Opcional — ex.: em jejum"
              />
            </Field>

            <FieldError>{formError}</FieldError>
          </FieldGroup>

          <SheetFooter className="px-0">
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner data-icon="inline-start" /> : null}
              {editing ? "Salvar alterações" : "Registrar"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
