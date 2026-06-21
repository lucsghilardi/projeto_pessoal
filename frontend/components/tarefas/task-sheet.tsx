"use client";

import { useEffect, useState } from "react";
import { Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  Field,
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
import { appToast } from "@/lib/toast";
import { PRIORITY_META, PRIORITY_OPTIONS, toDateInputValue } from "@/lib/tarefas";
import { createTask, deleteTask, updateTask } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Task, TaskColumn, TaskPriority } from "@/types/Task";

import { TimeEntriesSection } from "./time-entries-section";

type TaskSheetProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  projectId: number;
  columns: TaskColumn[];
  task?: Task | null;
  defaultColumnId?: number | null;
  onCreated?: (task: Task) => void;
  onUpdated?: (task: Task) => void;
  onDeleted?: (taskId: number) => void;
  onTimeChanged?: (taskId: number, totalSeconds: number) => void;
};

export function TaskSheet({
  open,
  onOpenChange,
  projectId,
  columns,
  task,
  defaultColumnId,
  onCreated,
  onUpdated,
  onDeleted,
  onTimeChanged,
}: TaskSheetProps) {
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [priority, setPriority] = useState<TaskPriority>("medium");
  const [dueDate, setDueDate] = useState("");
  const [columnId, setColumnId] = useState<number | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setTitle(task?.title ?? "");
      setDescription(task?.description ?? "");
      setPriority(task?.priority ?? "medium");
      setDueDate(toDateInputValue(task?.due_date));
      setColumnId(task?.task_column_id ?? defaultColumnId ?? columns[0]?.id ?? null);
      setError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, task, defaultColumnId]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      if (task) {
        const updated = await updateTask(task.id, {
          title: title.trim(),
          description: description.trim() || undefined,
          priority,
          due_date: dueDate || null,
        });
        appToast.success("Tarefa atualizada.");
        onUpdated?.(updated);
      } else {
        if (!columnId) {
          setError("Selecione uma coluna.");
          setSaving(false);
          return;
        }

        const created = await createTask({
          project_id: projectId,
          task_column_id: columnId,
          title: title.trim(),
          description: description.trim() || undefined,
          priority,
          due_date: dueDate || null,
        });
        appToast.success("Tarefa criada.");
        onCreated?.(created);
      }

      onOpenChange(false);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : "Não foi possível salvar a tarefa.";
      setError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!task) {
      return;
    }

    setDeleting(true);

    try {
      await deleteTask(task.id);
      appToast.success("Tarefa removida.");
      onDeleted?.(task.id);
      onOpenChange(false);
    } catch (err) {
      appToast.error(
        err instanceof ApiError ? err.message : "Não foi possível remover a tarefa.",
      );
    } finally {
      setDeleting(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{task ? "Editar tarefa" : "Nova tarefa"}</SheetTitle>
          <SheetDescription>
            Defina prioridade e prazo. A pontualidade rende XP extra ao concluir.
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <FieldGroup className="gap-4 overflow-y-auto px-4">
            <Field>
              <FieldLabel htmlFor="task-title">Título</FieldLabel>
              <Input
                id="task-title"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                placeholder="O que precisa ser feito?"
                required
                autoFocus
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="task-description">Descrição</FieldLabel>
              <textarea
                id="task-description"
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                placeholder="Opcional"
                rows={3}
                className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px]"
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="task-priority">Prioridade</FieldLabel>
              <Select
                value={priority}
                onValueChange={(value) => setPriority(value as TaskPriority)}
              >
                <SelectTrigger id="task-priority">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PRIORITY_OPTIONS.map((option) => (
                    <SelectItem key={option} value={option}>
                      {PRIORITY_META[option].label} · {PRIORITY_META[option].xp} XP
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field>
              <FieldLabel htmlFor="task-due">Prazo (data de conclusão)</FieldLabel>
              <Input
                id="task-due"
                type="date"
                value={dueDate}
                onChange={(event) => setDueDate(event.target.value)}
              />
            </Field>

            {!task ? (
              <Field>
                <FieldLabel htmlFor="task-column">Coluna</FieldLabel>
                <Select
                  value={columnId ? String(columnId) : undefined}
                  onValueChange={(value) => setColumnId(Number(value))}
                >
                  <SelectTrigger id="task-column">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    {columns.map((column) => (
                      <SelectItem key={column.id} value={String(column.id)}>
                        {column.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            ) : null}

            {task ? (
              <TimeEntriesSection
                taskId={task.id}
                onTotalChange={(seconds) => onTimeChanged?.(task.id, seconds)}
              />
            ) : null}

            {error ? <FieldError>{error}</FieldError> : null}
          </FieldGroup>

          <SheetFooter className="flex-row justify-between">
            {task ? (
              <Button
                type="button"
                variant="ghost"
                className="text-destructive hover:text-destructive"
                onClick={handleDelete}
                disabled={deleting || saving}
              >
                {deleting ? <Spinner /> : <Trash2 className="size-4" />}
                Excluir
              </Button>
            ) : (
              <span />
            )}

            <Button type="submit" disabled={saving || deleting}>
              {saving ? <Spinner /> : null}
              {task ? "Salvar" : "Criar tarefa"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
