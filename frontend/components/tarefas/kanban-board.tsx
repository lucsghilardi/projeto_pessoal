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

    setColumns((prev) => {
      const from = prev.find((c) => c.id === activeColumn);
      const to = prev.find((c) => c.id === overColumn);
      if (!from || !to) {
        return prev;
      }

      const activeTaskId = taskIdOf(active.id);
      const moving = from.tasks.find((t) => t.id === activeTaskId);
      if (!moving) {
        return prev;
      }

      let insertIndex = isColumnId(over.id)
        ? to.tasks.length
        : to.tasks.findIndex((t) => t.id === taskIdOf(over.id));
      if (insertIndex < 0) {
        insertIndex = to.tasks.length;
      }

      return prev.map((c) => {
        if (c.id === activeColumn) {
          return { ...c, tasks: c.tasks.filter((t) => t.id !== activeTaskId) };
        }
        if (c.id === overColumn) {
          const next = [...c.tasks];
          next.splice(insertIndex, 0, { ...moving, task_column_id: overColumn });
          return { ...c, tasks: next };
        }
        return c;
      });
    });
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    setActiveTask(null);

    if (!over) {
      return;
    }

    const activeTaskId = taskIdOf(active.id);
    const columnId = findColumnId(active.id);
    if (columnId == null) {
      return;
    }

    const column = columnsRef.current.find((c) => c.id === columnId);
    if (!column) {
      return;
    }

    const oldIndex = column.tasks.findIndex((t) => t.id === activeTaskId);
    let target = isColumnId(over.id)
      ? column.tasks.length - 1
      : column.tasks.findIndex((t) => t.id === taskIdOf(over.id));
    if (target < 0) {
      target = Math.max(0, column.tasks.length - 1);
    }

    if (oldIndex !== target) {
      setColumns((prev) =>
        prev.map((c) =>
          c.id === columnId
            ? { ...c, tasks: arrayMove(c.tasks, oldIndex, target) }
            : c,
        ),
      );
    }

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
