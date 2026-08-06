"use client";

import { useEffect, useState } from "react";

import { todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { createSaudeRefeicao, updateSaudeRefeicao } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeRefeicao, SaudeRefeicaoTipo } from "@/types/Saude";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

const TIPOS: Array<{ value: SaudeRefeicaoTipo; label: string }> = [
  { value: "cafe_da_manha", label: "Café da manhã" },
  { value: "almoco", label: "Almoço" },
  { value: "jantar", label: "Jantar" },
  { value: "lanche", label: "Lanche" },
  { value: "outro", label: "Outro" },
];

function horaAgora() {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: SaudeRefeicao | null;
  onSaved: () => void;
};

export function RefeicaoSheet({ open, onOpenChange, editing, onSaved }: Props) {
  const [data, setData] = useState(todayISO());
  const [horario, setHorario] = useState(horaAgora());
  const [nome, setNome] = useState("");
  const [tipo, setTipo] = useState<string>("outro");
  const [calorias, setCalorias] = useState("");
  const [proteinas, setProteinas] = useState("");
  const [carboidratos, setCarboidratos] = useState("");
  const [gorduras, setGorduras] = useState("");
  const [observacao, setObservacao] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setData(editing ? editing.data.slice(0, 10) : todayISO());
    setHorario(editing ? editing.horario.slice(0, 5) : horaAgora());
    setNome(editing?.nome ?? "");
    setTipo(editing?.tipo ?? "outro");
    setCalorias(editing ? String(editing.calorias) : "");
    setProteinas(editing?.proteinas_g ?? "");
    setCarboidratos(editing?.carboidratos_g ?? "");
    setGorduras(editing?.gorduras_g ?? "");
    setObservacao(editing?.observacao ?? "");
    setFormError(null);
  }, [open, editing]);

  function numeroOuNull(valor: string): number | null {
    if (!valor.trim()) {
      return null;
    }
    const n = Number(valor.replace(",", "."));
    return Number.isNaN(n) ? null : n;
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    const kcal = Number(calorias);
    if (!nome.trim()) {
      setFormError("Dê um nome à refeição.");
      return;
    }
    if (!calorias.trim() || Number.isNaN(kcal) || kcal < 0) {
      setFormError("Informe as calorias.");
      return;
    }

    const payload = {
      data,
      horario,
      nome: nome.trim(),
      tipo: (tipo || null) as SaudeRefeicaoTipo | null,
      calorias: Math.round(kcal),
      proteinas_g: numeroOuNull(proteinas),
      carboidratos_g: numeroOuNull(carboidratos),
      gorduras_g: numeroOuNull(gorduras),
      observacao: observacao.trim() || null,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeRefeicao(editing.id, payload);
        appToast.success("Refeição atualizada.");
      } else {
        await createSaudeRefeicao(payload);
        appToast.success("Refeição registrada.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a refeição.";
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
          <SheetTitle>{editing ? "Editar refeição" : "Registrar refeição"}</SheetTitle>
          <SheetDescription>
            {editing
              ? "Ajuste livremente — inclusive as estimativas feitas pela IA."
              : "Lançamento manual. Pelo WhatsApp, basta mandar a foto do prato para você mesmo."}
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <Field>
              <FieldLabel htmlFor="refeicao-nome">Refeição</FieldLabel>
              <Input
                id="refeicao-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Frango grelhado com arroz e salada"
                required
              />
            </Field>

            <div className="grid grid-cols-3 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-data">Data</FieldLabel>
                <Input
                  id="refeicao-data"
                  type="date"
                  value={data}
                  onChange={(e) => setData(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-horario">Horário</FieldLabel>
                <Input
                  id="refeicao-horario"
                  type="time"
                  value={horario}
                  onChange={(e) => setHorario(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-tipo">Tipo</FieldLabel>
                <Select value={tipo} onValueChange={setTipo}>
                  <SelectTrigger id="refeicao-tipo" className="w-full">
                    <SelectValue placeholder="Tipo" />
                  </SelectTrigger>
                  <SelectContent>
                    {TIPOS.map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-calorias">Calorias (kcal)</FieldLabel>
                <Input
                  id="refeicao-calorias"
                  type="number"
                  min={0}
                  max={5000}
                  value={calorias}
                  onChange={(e) => setCalorias(e.target.value)}
                  placeholder="620"
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-proteinas">Proteínas (g)</FieldLabel>
                <Input
                  id="refeicao-proteinas"
                  type="number"
                  step="0.1"
                  min={0}
                  max={500}
                  value={proteinas}
                  onChange={(e) => setProteinas(e.target.value)}
                  placeholder="45"
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-carboidratos">Carboidratos (g)</FieldLabel>
                <Input
                  id="refeicao-carboidratos"
                  type="number"
                  step="0.1"
                  min={0}
                  value={carboidratos}
                  onChange={(e) => setCarboidratos(e.target.value)}
                  placeholder="55"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-gorduras">Gorduras (g)</FieldLabel>
                <Input
                  id="refeicao-gorduras"
                  type="number"
                  step="0.1"
                  min={0}
                  value={gorduras}
                  onChange={(e) => setGorduras(e.target.value)}
                  placeholder="18"
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="refeicao-observacao">Observação</FieldLabel>
              <Input
                id="refeicao-observacao"
                value={observacao}
                onChange={(e) => setObservacao(e.target.value)}
                placeholder="Opcional"
              />
              <FieldDescription>
                Macros são opcionais — calorias bastam para o acompanhamento.
              </FieldDescription>
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
