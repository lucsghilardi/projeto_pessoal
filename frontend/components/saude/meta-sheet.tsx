"use client";

import { useEffect, useState } from "react";

import { appToast } from "@/lib/toast";
import { saveSaudeMeta } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeMeta, SaudeNivelAtividade } from "@/types/Saude";
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

const NIVEIS_ATIVIDADE: Array<{ value: SaudeNivelAtividade; label: string }> = [
  { value: "sedentario", label: "Sedentário — pouco ou nenhum exercício" },
  { value: "leve", label: "Leve — exercício 1-3x por semana" },
  { value: "moderado", label: "Moderado — exercício 3-5x por semana" },
  { value: "intenso", label: "Intenso — exercício 6-7x por semana" },
  { value: "atleta", label: "Atleta — treino pesado diário" },
];

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
  const [sexo, setSexo] = useState("");
  const [nascimento, setNascimento] = useState("");
  const [atividade, setAtividade] = useState("");
  const [gastoDinamico, setGastoDinamico] = useState(false);
  const [caloriasAlvo, setCaloriasAlvo] = useState("");
  const [proteinasAlvo, setProteinasAlvo] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setPesoMeta(meta?.peso_meta_kg ?? "");
    setDataAlvo(meta?.data_alvo ? meta.data_alvo.slice(0, 10) : "");
    setAltura(meta?.altura_cm != null ? String(meta.altura_cm) : "");
    setSexo(meta?.sexo ?? "");
    setNascimento(meta?.data_nascimento ? meta.data_nascimento.slice(0, 10) : "");
    setAtividade(meta?.nivel_atividade ?? "sedentario");
    setGastoDinamico(meta?.gasto_dinamico ?? false);
    setCaloriasAlvo(meta?.calorias_alvo != null ? String(meta.calorias_alvo) : "");
    setProteinasAlvo(meta?.proteinas_alvo_g != null ? String(meta.proteinas_alvo_g) : "");
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
        sexo: sexo === "M" || sexo === "F" ? sexo : null,
        data_nascimento: nascimento || null,
        nivel_atividade: (atividade || null) as SaudeNivelAtividade | null,
        gasto_dinamico: gastoDinamico,
        calorias_alvo: caloriasAlvo.trim() ? Number(caloriasAlvo) : null,
        proteinas_alvo_g: proteinasAlvo.trim() ? Number(proteinasAlvo) : null,
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
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>Meta e perfil</SheetTitle>
          <SheetDescription>
            A meta vira a linha de referência dos gráficos; o perfil alimenta o
            cálculo de calorias (TMB/TDEE) e a projeção de emagrecimento.
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

            <div className="grid grid-cols-2 gap-3">
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
              </Field>
              <Field>
                <FieldLabel htmlFor="meta-sexo">Sexo</FieldLabel>
                <Select value={sexo} onValueChange={setSexo}>
                  <SelectTrigger id="meta-sexo" className="w-full">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="M">Masculino</SelectItem>
                    <SelectItem value="F">Feminino</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="meta-nascimento">Nascimento</FieldLabel>
                <Input
                  id="meta-nascimento"
                  type="date"
                  value={nascimento}
                  onChange={(e) => setNascimento(e.target.value)}
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="meta-atividade">Atividade</FieldLabel>
                <Select value={atividade} onValueChange={setAtividade}>
                  <SelectTrigger id="meta-atividade" className="w-full">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    {NIVEIS_ATIVIDADE.map((nivel) => (
                      <SelectItem key={nivel.value} value={nivel.value}>
                        {nivel.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>
            <FieldDescription>
              Sexo, nascimento, altura e atividade são usados na fórmula de
              Mifflin-St Jeor para calcular seu gasto calórico diário.
            </FieldDescription>

            <Field>
              <label className="flex items-start gap-3">
                <input
                  type="checkbox"
                  checked={gastoDinamico}
                  onChange={(e) => setGastoDinamico(e.target.checked)}
                  className="mt-0.5 size-4 accent-emerald-600"
                />
                <span>
                  <span className="text-sm font-medium">Usar o gasto real do exercício</span>
                  <span className="block text-xs text-muted-foreground">
                    Sua meta sobe conforme você treina: o gasto do dia entra no
                    lugar do multiplicador de atividade, que já embutia o treino.
                    Em dia parado, a meta cai.
                  </span>
                </span>
              </label>
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="meta-calorias">Calorias/dia</FieldLabel>
                <Input
                  id="meta-calorias"
                  type="number"
                  min={800}
                  max={6000}
                  value={caloriasAlvo}
                  onChange={(e) => setCaloriasAlvo(e.target.value)}
                  placeholder="Automático"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="meta-proteinas">Proteína (g/dia)</FieldLabel>
                <Input
                  id="meta-proteinas"
                  type="number"
                  min={20}
                  max={400}
                  value={proteinasAlvo}
                  onChange={(e) => setProteinasAlvo(e.target.value)}
                  placeholder="Automático"
                />
              </Field>
            </div>
            <FieldDescription>
              Deixe em branco para o cálculo automático: déficit seguro derivado
              da sua meta e 1,8g de proteína por kg.
            </FieldDescription>

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
