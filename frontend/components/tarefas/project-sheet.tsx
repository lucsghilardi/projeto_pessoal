"use client";

import { useEffect, useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Field,
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
import { cn } from "@/lib/utils";
import { projectColorChoices } from "@/lib/tarefas";
import { createProject, updateProject } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Project, ProjectBoard } from "@/types/Task";

type ProjectSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  project?: Project | null;
  onCreated?: (project: ProjectBoard) => void;
  onUpdated?: (project: Project) => void;
};

export function ProjectSheet({
  open,
  onOpenChange,
  project,
  onCreated,
  onUpdated,
}: ProjectSheetProps) {
  const colors = projectColorChoices();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState(colors[0]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setName(project?.name ?? "");
      setDescription(project?.description ?? "");
      setColor(project?.color ?? colors[0]);
      setError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, project]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const payload = {
        name: name.trim(),
        color,
        description: description.trim() || undefined,
      };

      if (project) {
        const updated = await updateProject(project.id, payload);
        appToast.success("Projeto atualizado.");
        onUpdated?.(updated);
      } else {
        const created = await createProject(payload);
        appToast.success("Projeto criado com colunas padrão.");
        onCreated?.(created);
      }

      onOpenChange(false);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : "Não foi possível salvar o projeto.";
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
          <SheetTitle>{project ? "Editar projeto" : "Novo projeto"}</SheetTitle>
          <SheetDescription>
            {project
              ? "Atualize o nome, a cor ou a descrição do projeto."
              : "Cada projeto novo já vem com as colunas A fazer, Fazendo, Em revisão e Concluído."}
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <FieldGroup className="gap-4 overflow-y-auto px-4">
            <Field>
              <FieldLabel htmlFor="project-name">Nome</FieldLabel>
              <Input
                id="project-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Ex.: Reforma da casa"
                required
                autoFocus
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="project-description">Descrição</FieldLabel>
              <textarea
                id="project-description"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                placeholder="Opcional"
                rows={3}
                className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px]"
              />
            </Field>

            <Field>
              <FieldLabel>Cor</FieldLabel>
              <div className="flex flex-wrap gap-2">
                {colors.map((option) => (
                  <button
                    key={option}
                    type="button"
                    onClick={() => setColor(option)}
                    className={cn(
                      "size-8 rounded-full border-2 transition",
                      color === option
                        ? "border-foreground scale-110"
                        : "border-transparent",
                    )}
                    style={{ backgroundColor: option }}
                    aria-label={`Cor ${option}`}
                  />
                ))}
              </div>
            </Field>

            {error ? <FieldError>{error}</FieldError> : null}
          </FieldGroup>

          <SheetFooter>
            <Button type="submit" disabled={saving}>
              {saving ? <Spinner /> : null}
              {project ? "Salvar alterações" : "Criar projeto"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
