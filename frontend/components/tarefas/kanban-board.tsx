"use client";

import { Dispatch, SetStateAction, useEffect, useRef, useState } from "react";
import {
  closestCorners,
  DndContext,
  type DragEndEvent,
  type DragOverEvent,
  type DragStartEvent,
  DragOverlay,
  PointerSensor,
  type UniqueIdentifier,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import { arrayMove } from "@dnd-kit/sortable";
import { Plus } from "lucide-react";

import { Button } from "@/components/ui/button";
import type { Task, TaskColumn } from "@/types/Task";

import { KanbanColumn } from "./kanban-column";
import { TaskCard } from "./task-card";

export type BoardColumn = TaskColumn & { tasks: Task[] };

const taskIdOf = (id: UniqueIdentifier) => Number(String(id).replace("task-", ""));
const isColumnId = (id: UniqueIdentifier) => String(id).startsWith("column-");

type KanbanBoardProps = {
  columns: BoardColumn[];
  setColumns: Dispatch<SetStateAction<BoardColumn[]>>;
  onMovePersist: (taskId: number, columnId: number, position: number) => void;
  onAddTask: (columnId: number) => void;
  onEditTask: (task: Task) => void;
  onAddColumn: () => void;
  onEditColumn: (column: TaskColumn) => void;
  onDeleteColumn: (column: TaskColumn) => void;
  onMoveColumn: (column: TaskColumn, direction: -1 | 1) => void;
};

export function KanbanBoard({
  columns,
  setColumns,
  onMovePersist,
  onAddTask,
  onEditTask,
  onAddColumn,
  onEditColumn,
  onDeleteColumn,
  onMoveColumn,
}: KanbanBoardProps) {
  const [activeTask, setActiveTask] = useState<Task | null>(null);

  // Espelho do estado para leitura nas handlers sem closures defasadas.
  const columnsRef = useRef(columns);
  useEffect(() => {
    columnsRef.current = columns;
  }, [columns]);

  // O drop pode chegar antes de o React recommitar o resultado do último
  // `dragOver`. Sem atualizar o espelho aqui, a handler seguinte leria a
  // coluna de origem e persistiria a tarefa de volta nela.
  function commitColumns(next: BoardColumn[]) {
    columnsRef.current = next;
    setColumns(next);
  }

  // Índice em que a tarefa arrastada entra na coluna de destino: antes do card
  // sob o cursor, ou no fim quando o alvo é a área vazia da coluna.
  function insertIndexFor(to: BoardColumn, overId: UniqueIdentifier) {
    if (isColumnId(overId)) {
      return to.tasks.length;
    }

    const index = to.tasks.findIndex((t) => t.id === taskIdOf(overId));

    return index < 0 ? to.tasks.length : index;
  }

  function moveAcrossColumns(
    board: BoardColumn[],
    taskId: number,
    fromId: number,
    toId: number,
    insertIndex: number,
  ): BoardColumn[] | null {
    const from = board.find((c) => c.id === fromId);
    const to = board.find((c) => c.id === toId);
    const moving = from?.tasks.find((t) => t.id === taskId);
    if (!from || !to || !moving) {
      return null;
    }

    return board.map((c) => {
      if (c.id === fromId) {
        return { ...c, tasks: c.tasks.filter((t) => t.id !== taskId) };
      }
      if (c.id === toId) {
        const tasks = [...c.tasks];
        tasks.splice(insertIndex, 0, { ...moving, task_column_id: toId });
        return { ...c, tasks };
      }
      return c;
    });
  }

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
  );

  function findColumnId(id: UniqueIdentifier): number | null {
    if (isColumnId(id)) {
      return Number(String(id).replace("column-", ""));
    }

    const taskId = taskIdOf(id);
    const column = columnsRef.current.find((c) =>
      c.tasks.some((t) => t.id === taskId),
    );

    return column ? column.id : null;
  }

  function handleDragStart(event: DragStartEvent) {
    const taskId = taskIdOf(event.active.id);
    const task =
      columnsRef.current.flatMap((c) => c.tasks).find((t) => t.id === taskId) ?? null;
    setActiveTask(task);
  }

  function handleDragOver(event: DragOverEvent) {
    const { active, over } = event;
    if (!over) {
      return;
    }

    const activeColumn = findColumnId(active.id);
    const overColumn = findColumnId(over.id);

    if (activeColumn == null || overColumn == null || activeColumn === overColumn) {
      return;
    }

    const prev = columnsRef.current;
    const to = prev.find((c) => c.id === overColumn);
    if (!to) {
      return;
    }

    const next = moveAcrossColumns(
      prev,
      taskIdOf(active.id),
      activeColumn,
      overColumn,
      insertIndexFor(to, over.id),
    );

    if (next) {
      commitColumns(next);
    }
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    setActiveTask(null);

    if (!over) {
      return;
    }

    const activeTaskId = taskIdOf(active.id);
    // A coluna de destino vem do `over` do evento, e nao de onde a tarefa esta
    // no estado: num drop logo apos entrar na coluna o `dragOver` pode nem ter
    // rodado, e ler o estado devolveria a tarefa para a coluna de origem.
    const sourceColumnId = findColumnId(active.id);
    const columnId = findColumnId(over.id);
    if (sourceColumnId == null || columnId == null) {
      return;
    }

    let next = columnsRef.current;
    let target: number;

    if (sourceColumnId !== columnId) {
      const to = next.find((c) => c.id === columnId);
      if (!to) {
        return;
      }

      target = insertIndexFor(to, over.id);
      const moved = moveAcrossColumns(
        next,
        activeTaskId,
        sourceColumnId,
        columnId,
        target,
      );
      if (!moved) {
        return;
      }
      next = moved;
    } else {
      const column = next.find((c) => c.id === columnId);
      if (!column) {
        return;
      }

      const oldIndex = column.tasks.findIndex((t) => t.id === activeTaskId);
      target = isColumnId(over.id)
        ? column.tasks.length - 1
        : column.tasks.findIndex((t) => t.id === taskIdOf(over.id));
      if (target < 0) {
        target = Math.max(0, column.tasks.length - 1);
      }

      if (oldIndex >= 0 && oldIndex !== target) {
        next = next.map((c) =>
          c.id === columnId
            ? { ...c, tasks: arrayMove(c.tasks, oldIndex, target) }
            : c,
        );
      }
    }

    commitColumns(next);
    onMovePersist(activeTaskId, columnId, target);
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCorners}
      onDragStart={handleDragStart}
      onDragOver={handleDragOver}
      onDragEnd={handleDragEnd}
    >
      <div className="flex gap-4 overflow-x-auto pb-4">
        {columns.map((column, index) => (
          <KanbanColumn
            key={column.id}
            column={column}
            tasks={column.tasks}
            isFirst={index === 0}
            isLast={index === columns.length - 1}
            onAddTask={onAddTask}
            onEditTask={onEditTask}
            onEditColumn={onEditColumn}
            onDeleteColumn={onDeleteColumn}
            onMoveColumn={onMoveColumn}
          />
        ))}

        <div className="shrink-0">
          <Button
            type="button"
            variant="outline"
            onClick={onAddColumn}
            className="w-44 justify-start border-dashed"
          >
            <Plus className="size-4" />
            Nova coluna
          </Button>
        </div>
      </div>

      <DragOverlay>
        {activeTask ? <TaskCard task={activeTask} overlay /> : null}
      </DragOverlay>
    </DndContext>
  );
}
