"use client";

import { useEffect, useState } from "react";

import { appToast } from "@/lib/toast";
import { saveSaudeMeta } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeMeta } from "@/types/Saude";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
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
  meta: SaudeMeta;
  onSaved: () => void;
};

export function MetaSheet({ open, onOpenChange, meta, onSaved }: Props) {
  const [pesoMeta, setPesoMeta] = useState("");
  const [dataAlvo, setDataAlvo] = useState("");
  const [altura, setAltura] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setPesoMeta(meta?.peso_meta_kg ?? "");
    setDataAlvo(meta?.data_alvo ? meta.data_alvo.slice(0, 10) : "");
    setAltura(meta?.altura_cm != null ? String(meta.altura_cm) : "");
    setFormError(null);
  }, [open, meta]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    const pesoValor = pesoMeta.trim() ? Number(pesoMeta.replace(",", ".")) : null;
    if (pesoValor !== null && Number.isNaN(pesoValor)) {
      setFormError("Peso da meta inválido.");
      return;
    }

    setSaving(true);
    try {
      await saveSaudeMeta({
        peso_meta_kg: pesoValor,
        data_alvo: dataAlvo || null,
        altura_cm: altura.trim() ? Number(altura) : null,
      });
      appToast.success("Meta salva.");
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a meta.";
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
          <SheetTitle>Meta de peso</SheetTitle>
          <SheetDescription>
            A meta vira a linha de referência do gráfico de evolução.
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="meta-peso">Peso alvo (kg)</FieldLabel>
                <Input
                  id="meta-peso"
                  type="number"
                  step="0.1"
                  min={20}
                  max={400}
                  value={pesoMeta}
                  onChange={(e) => setPesoMeta(e.target.value)}
                  placeholder="87.5"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="meta-data">Data alvo</FieldLabel>
                <Input
                  id="meta-data"
                  type="date"
                  value={dataAlvo}
                  onChange={(e) => setDataAlvo(e.target.value)}
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="meta-altura">Altura (cm)</FieldLabel>
              <Input
                id="meta-altura"
                type="number"
                min={100}
                max={250}
                value={altura}
                onChange={(e) => setAltura(e.target.value)}
                placeholder="178"
              />
              <FieldDescription>
                Usada só para calcular o IMC nos indicadores.
              </FieldDescription>
            </Field>

            <FieldError>{formError}</FieldError>
          </FieldGroup>

          <SheetFooter className="px-0">
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner data-icon="inline-start" /> : null}
              Salvar meta
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
