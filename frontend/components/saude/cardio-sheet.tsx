"use client";

import { useEffect, useState } from "react";

import { todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { createSaudeCardio, updateSaudeCardio } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeCardioModalidade,
  SaudeCardioSessao,
  SaudeTreino,
} from "@/types/Saude";
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

export const MODALIDADES: Array<{ value: SaudeCardioModalidade; label: string }> = [
  { value: "corrida_rua", label: "Corrida na rua" },
  { value: "esteira", label: "Esteira" },
  { value: "bike", label: "Bike" },
  { value: "eliptico", label: "Elíptico" },
  { value: "caminhada", label: "Caminhada" },
  { value: "outro", label: "Outro" },
];

/** Sentinela do Select: o shadcn não aceita SelectItem com value="". */
const SEM_FICHA = "avulso";

function horaAgora() {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: SaudeCardioSessao | null;
  treinos: SaudeTreino[];
  onSaved: () => void;
};

export function CardioSheet({ open, onOpenChange, editing, treinos, onSaved }: Props) {
  const [data, setData] = useState(todayISO());
  const [horario, setHorario] = useState(horaAgora());
  const [modalidade, setModalidade] = useState<string>("corrida_rua");
  const [duracao, setDuracao] = useState("");
  const [distancia, setDistancia] = useState("");
  const [calorias, setCalorias] = useState("");
  const [fcMedia, setFcMedia] = useState("");
  const [fcMaxima, setFcMaxima] = useState("");
  const [treinoId, setTreinoId] = useState<string>(SEM_FICHA);
  const [observacao, setObservacao] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setData(editing ? editing.data.slice(0, 10) : todayISO());
    setHorario(editing?.horario ? editing.horario.slice(0, 5) : horaAgora());
    setModalidade(editing?.modalidade ?? "corrida_rua");
    setDuracao(editing ? String(editing.duracao_min) : "");
    setDistancia(editing?.distancia_km ?? "");
    setCalorias(editing?.calorias != null ? String(editing.calorias) : "");
    setFcMedia(editing?.fc_media != null ? String(editing.fc_media) : "");
    setFcMaxima(editing?.fc_maxima != null ? String(editing.fc_maxima) : "");
    setTreinoId(editing?.treino_id != null ? String(editing.treino_id) : SEM_FICHA);
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

    const minutos = Number(duracao);
    if (!duracao.trim() || Number.isNaN(minutos) || minutos < 1) {
      setFormError("Informe a duração em minutos.");
      return;
    }

    const payload = {
      data,
      horario: horario || null,
      modalidade: modalidade as SaudeCardioModalidade,
      duracao_min: Math.round(minutos),
      distancia_km: numeroOuNull(distancia),
      calorias: numeroOuNull(calorias),
      fc_media: numeroOuNull(fcMedia),
      fc_maxima: numeroOuNull(fcMaxima),
      treino_id: treinoId === SEM_FICHA ? null : Number(treinoId),
      observacao: observacao.trim() || null,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeCardio(editing.id, payload);
        appToast.success("Cardio atualizado.");
      } else {
        await createSaudeCardio(payload);
        appToast.success("Cardio registrado.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar o cardio.";
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
          <SheetTitle>{editing ? "Editar cardio" : "Registrar cardio"}</SheetTitle>
          <SheetDescription>
            {editing
              ? "Ajuste livremente — inclusive o que veio do relógio."
              : "Pode registrar mais de uma sessão no mesmo dia."}
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <Field>
              <FieldLabel htmlFor="cardio-modalidade">Modalidade</FieldLabel>
              <Select value={modalidade} onValueChange={setModalidade}>
                <SelectTrigger id="cardio-modalidade" className="w-full">
                  <SelectValue placeholder="Modalidade" />
                </SelectTrigger>
                <SelectContent>
                  {MODALIDADES.map((m) => (
                    <SelectItem key={m.value} value={m.value}>
                      {m.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <div className="grid grid-cols-3 gap-3">
              <Field>
                <FieldLabel htmlFor="cardio-data">Data</FieldLabel>
                <Input
                  id="cardio-data"
                  type="date"
                  value={data}
                  onChange={(e) => setData(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="cardio-horario">Horário</FieldLabel>
                <Input
                  id="cardio-horario"
                  type="time"
                  value={horario}
                  onChange={(e) => setHorario(e.target.value)}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="cardio-duracao">Duração (min)</FieldLabel>
                <Input
                  id="cardio-duracao"
                  type="number"
                  min={1}
                  max={600}
                  value={duracao}
                  onChange={(e) => setDuracao(e.target.value)}
                  placeholder="30"
                  required
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="cardio-distancia">Distância (km)</FieldLabel>
                <Input
                  id="cardio-distancia"
                  type="number"
                  step="0.01"
                  min={0}
                  value={distancia}
                  onChange={(e) => setDistancia(e.target.value)}
                  placeholder="3,15"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="cardio-calorias">Calorias (kcal)</FieldLabel>
                <Input
                  id="cardio-calorias"
                  type="number"
                  min={0}
                  max={5000}
                  value={calorias}
                  onChange={(e) => setCalorias(e.target.value)}
                  placeholder="288"
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="cardio-fc-media">FC média (bpm)</FieldLabel>
                <Input
                  id="cardio-fc-media"
                  type="number"
                  min={30}
                  max={230}
                  value={fcMedia}
                  onChange={(e) => setFcMedia(e.target.value)}
                  placeholder="138"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="cardio-fc-maxima">FC máxima (bpm)</FieldLabel>
                <Input
                  id="cardio-fc-maxima"
                  type="number"
                  min={30}
                  max={230}
                  value={fcMaxima}
                  onChange={(e) => setFcMaxima(e.target.value)}
                  placeholder="164"
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="cardio-treino">Ficha</FieldLabel>
              <Select value={treinoId} onValueChange={setTreinoId}>
                <SelectTrigger id="cardio-treino" className="w-full">
                  <SelectValue placeholder="Ficha" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={SEM_FICHA}>Avulso (sem ficha)</SelectItem>
                  {treinos.map((t) => (
                    <SelectItem key={t.id} value={String(t.id)}>
                      {t.nome}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FieldDescription>
                Use a ficha do treino quando for o cardio do fim do A ou B.
              </FieldDescription>
            </Field>

            <Field>
              <FieldLabel htmlFor="cardio-observacao">Observação</FieldLabel>
              <Input
                id="cardio-observacao"
                value={observacao}
                onChange={(e) => setObservacao(e.target.value)}
                placeholder="Opcional"
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
