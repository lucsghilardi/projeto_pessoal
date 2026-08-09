"use client";

import { useEffect, useState } from "react";

import { todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { createSaudeSono, updateSaudeSono } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeSono } from "@/types/Saude";
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
  editing: SaudeSono | null;
  /** Pré-seleciona a data ao abrir para lançar uma noite específica. */
  dataInicial?: string;
  onSaved: () => void;
};

export function SonoSheet({
  open,
  onOpenChange,
  editing,
  dataInicial,
  onSaved,
}: Props) {
  const [data, setData] = useState(todayISO());
  const [horas, setHoras] = useState("");
  const [minutos, setMinutos] = useState("");
  const [inicio, setInicio] = useState("");
  const [fim, setFim] = useState("");
  const [observacao, setObservacao] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setData(editing ? editing.data.slice(0, 10) : (dataInicial ?? todayISO()));
    setHoras(editing ? String(Math.floor(editing.duracao_min / 60)) : "");
    setMinutos(editing ? String(editing.duracao_min % 60) : "");
    setInicio(editing?.inicio?.slice(0, 5) ?? "");
    setFim(editing?.fim?.slice(0, 5) ?? "");
    setObservacao(editing?.observacao ?? "");
    setFormError(null);
  }, [open, editing, dataInicial]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    const duracao = (Number(horas) || 0) * 60 + (Number(minutos) || 0);
    if (duracao < 1 || duracao > 1440) {
      setFormError("Informe quanto dormiu (entre 1 minuto e 24 horas).");
      return;
    }

    const campos = {
      duracao_min: duracao,
      inicio: inicio || null,
      fim: fim || null,
      observacao: observacao.trim() || null,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeSono(editing.id, campos);
        appToast.success("Noite atualizada.");
      } else {
        await createSaudeSono({ data, ...campos });
        appToast.success("Noite registrada.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a noite.";
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
          <SheetTitle>{editing ? "Editar noite" : "Registrar sono"}</SheetTitle>
          <SheetDescription>
            Use a data em que você <strong>acordou</strong> — é assim que o relógio
            indexa a noite. Uma noite por dia: registrar de novo substitui, e a
            noite salva aqui não é mais sobrescrita pelo Garmin.
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <Field>
              <FieldLabel htmlFor="sono-data">Data (dia em que acordou)</FieldLabel>
              <Input
                id="sono-data"
                type="date"
                value={data}
                onChange={(e) => setData(e.target.value)}
                disabled={editing !== null}
                required
              />
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="sono-horas">Horas dormidas</FieldLabel>
                <Input
                  id="sono-horas"
                  type="number"
                  min={0}
                  max={24}
                  value={horas}
                  onChange={(e) => setHoras(e.target.value)}
                  placeholder="7"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="sono-minutos">Minutos</FieldLabel>
                <Input
                  id="sono-minutos"
                  type="number"
                  min={0}
                  max={59}
                  value={minutos}
                  onChange={(e) => setMinutos(e.target.value)}
                  placeholder="30"
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="sono-inicio">Deitou às</FieldLabel>
                <Input
                  id="sono-inicio"
                  type="time"
                  value={inicio}
                  onChange={(e) => setInicio(e.target.value)}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="sono-fim">Acordou às</FieldLabel>
                <Input
                  id="sono-fim"
                  type="time"
                  value={fim}
                  onChange={(e) => setFim(e.target.value)}
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="sono-observacao">Observação</FieldLabel>
              <Input
                id="sono-observacao"
                value={observacao}
                onChange={(e) => setObservacao(e.target.value)}
                placeholder="Opcional — ex.: dormi sem o relógio"
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
