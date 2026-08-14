"use client";

import { useEffect, useState } from "react";

import { appToast } from "@/lib/toast";
import { createSaudeTreino, updateSaudeTreino } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { SaudeTreino, SaudeTreinoTipo } from "@/types/Saude";
import { Button } from "@/components/ui/button";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/components/ui/field";
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

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: SaudeTreino | null;
  onSaved: () => void;
};

const DESCRICAO_TIPO: Record<SaudeTreinoTipo, string> = {
  musculacao: "Séries, repetições e carga. É o tipo do Treino A/B.",
  cardio: "Blocos com duração e intensidade — corrida, bike, intervalado.",
};

export function TreinoSheet({ open, onOpenChange, editing, onSaved }: Props) {
  const [nome, setNome] = useState("");
  const [tipo, setTipo] = useState<SaudeTreinoTipo>("musculacao");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    setNome(editing?.nome ?? "");
    setTipo(editing?.tipo ?? "musculacao");
    setFormError(null);
  }, [open, editing]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!nome.trim()) {
      setFormError("Informe o nome da ficha.");
      return;
    }

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeTreino(editing.id, { nome: nome.trim(), tipo });
        appToast.success("Ficha atualizada.");
      } else {
        await createSaudeTreino({ nome: nome.trim(), tipo });
        appToast.success("Ficha criada.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a ficha.";
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
          <SheetTitle>{editing ? "Editar ficha" : "Nova ficha"}</SheetTitle>
          <SheetDescription>
            A ficha agrupa os exercícios; os blocos entram depois, um a um.
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <Field>
              <FieldLabel htmlFor="treino-nome">Nome</FieldLabel>
              <Input
                id="treino-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Ex.: Treino C"
                maxLength={60}
                required
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="treino-tipo">Tipo</FieldLabel>
              <Select
                value={tipo}
                onValueChange={(value) => setTipo(value as SaudeTreinoTipo)}
                disabled={saving}
              >
                <SelectTrigger id="treino-tipo" className="w-full">
                  <SelectValue placeholder="Selecione o tipo" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="musculacao">Musculação</SelectItem>
                  <SelectItem value="cardio">Cardio</SelectItem>
                </SelectContent>
              </Select>
              <FieldDescription>{DESCRICAO_TIPO[tipo]}</FieldDescription>
            </Field>

            <FieldError>{formError}</FieldError>
          </FieldGroup>

          <SheetFooter className="px-0">
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner data-icon="inline-start" /> : null}
              {editing ? "Salvar alterações" : "Criar ficha"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
