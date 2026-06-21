"use client";

import { useEffect, useState } from "react";

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
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";
import { appToast } from "@/lib/toast";
import { createColumn, updateColumn } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { TaskColumn } from "@/types/Task";

type ColumnSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  projectId: number;
  column?: TaskColumn | null;
  onCreated?: (column: TaskColumn) => void;
  onUpdated?: (column: TaskColumn) => void;
};

export function ColumnSheet({
  open,
  onOpenChange,
  projectId,
  column,
  onCreated,
  onUpdated,
}: ColumnSheetProps) {
  const [name, setName] = useState("");
  const [isDone, setIsDone] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setName(column?.name ?? "");
      setIsDone(column?.is_done_column ?? false);
      setError(null);
    }
  }, [open, column]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const payload = { name: name.trim(), is_done_column: isDone };

      if (column) {
        const updated = await updateColumn(column.id, payload);
        appToast.success("Coluna atualizada.");
        onUpdated?.(updated);
      } else {
        const created = await createColumn(projectId, payload);
        appToast.success("Coluna criada.");
        onCreated?.(created);
      }

      onOpenChange(false);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : "Não foi possível salvar a coluna.";
      setError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{column ? "Editar coluna" : "Nova coluna"}</SheetTitle>
          <SheetDescription>
            Marque uma coluna como &quot;concluído&quot; para que tarefas movidas até ela
            rendam XP.
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <FieldGroup className="gap-4 overflow-y-auto px-4">
            <Field>
              <FieldLabel htmlFor="column-name">Nome</FieldLabel>
              <Input
                id="column-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Ex.: Em testes"
                required
                autoFocus
              />
            </Field>

            <Field>
              <label className="flex items-center gap-3">
                <input
                  type="checkbox"
                  checked={isDone}
                  onChange={(event) => setIsDone(event.target.checked)}
                  className="size-4 accent-indigo-600"
                />
                <span className="text-sm font-medium">
                  Coluna de conclusão (concede XP)
                </span>
              </label>
              <FieldDescription>
                Ao arrastar uma tarefa para esta coluna ela é marcada como concluída.
              </FieldDescription>
            </Field>

            {error ? <FieldError>{error}</FieldError> : null}
          </FieldGroup>

          <SheetFooter>
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner /> : null}
              {column ? "Salvar" : "Criar coluna"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
