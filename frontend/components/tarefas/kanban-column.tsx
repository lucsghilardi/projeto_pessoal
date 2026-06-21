"use client";

import { useDroppable } from "@dnd-kit/core";
import { SortableContext, verticalListSortingStrategy } from "@dnd-kit/sortable";
import {
  ChevronLeft,
  ChevronRight,
  MoreVertical,
  Pencil,
  Plus,
  Trash2,
} from "lucide-react";

import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";
import type { Task, TaskColumn as TaskColumnType } from "@/types/Task";

import { TaskCard } from "./task-card";

type KanbanColumnProps = {
  column: TaskColumnType;
  tasks: Task[];
  isFirst: boolean;
  isLast: boolean;
  onAddTask: (columnId: number) => void;
  onEditTask: (task: Task) => void;
  onEditColumn: (column: TaskColumnType) => void;
  onDeleteColumn: (column: TaskColumnType) => void;
  onMoveColumn: (column: TaskColumnType, direction: -1 | 1) => void;
};

export function KanbanColumn({
  column,
  tasks,
  isFirst,
  isLast,
  onAddTask,
  onEditTask,
  onEditColumn,
  onDeleteColumn,
  onMoveColumn,
}: KanbanColumnProps) {
  const { setNodeRef, isOver } = useDroppable({
    id: `column-${column.id}`,
    data: { type: "column", columnId: column.id },
  });

  return (
    <div className="flex w-72 shrink-0 flex-col rounded-xl border bg-muted/40">
      <div className="flex items-center justify-between gap-2 px-3 py-2.5">
        <div className="flex min-w-0 items-center gap-2">
          {column.is_done_column ? (
            <span className="size-2 shrink-0 rounded-full bg-emerald-500" />
          ) : null}
          <h3 className="truncate text-sm font-semibold">{column.name}</h3>
          <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">
            {tasks.length}
          </span>
        </div>

        <div className="flex items-center gap-0.5">
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => onAddTask(column.id)}
            aria-label="Adicionar tarefa"
          >
            <Plus className="size-4" />
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon-sm" aria-label="Opções da coluna">
                <MoreVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => onEditColumn(column)}>
                <Pencil className="size-4" />
                Editar coluna
              </DropdownMenuItem>
              <DropdownMenuItem
                disabled={isFirst}
                onClick={() => onMoveColumn(column, -1)}
              >
                <ChevronLeft className="size-4" />
                Mover para esquerda
              </DropdownMenuItem>
              <DropdownMenuItem
                disabled={isLast}
                onClick={() => onMoveColumn(column, 1)}
              >
                <ChevronRight className="size-4" />
                Mover para direita
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                variant="destructive"
                onClick={() => onDeleteColumn(column)}
              >
                <Trash2 className="size-4" />
                Excluir coluna
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <div
        ref={setNodeRef}
        className={cn(
          "flex min-h-24 flex-1 flex-col gap-2 px-2 pb-3 transition-colors",
          isOver && "bg-indigo-50",
        )}
      >
        <SortableContext
          items={tasks.map((task) => `task-${task.id}`)}
          strategy={verticalListSortingStrategy}
        >
          {tasks.map((task) => (
            <TaskCard key={task.id} task={task} onEdit={onEditTask} />
          ))}
        </SortableContext>

        {tasks.length === 0 ? (
          <button
            type="button"
            onClick={() => onAddTask(column.id)}
            className="mt-1 rounded-lg border border-dashed py-6 text-center text-xs text-muted-foreground transition hover:border-indigo-300 hover:text-foreground"
          >
            Solte tarefas aqui ou clique para adicionar
          </button>
        ) : null}
      </div>
    </div>
  );
}
