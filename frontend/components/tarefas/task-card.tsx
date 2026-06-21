"use client";

import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { CalendarClock, CheckCircle2, Sparkles, Timer } from "lucide-react";

import { cn } from "@/lib/utils";
import { formatDuration } from "@/lib/format";
import { PRIORITY_META, formatDueDate, isOverdue } from "@/lib/tarefas";
import type { Task } from "@/types/Task";

import { TimerButton } from "./timer-button";
import { useTimer } from "./timer-context";

export function TaskCard({
  task,
  onEdit,
  overlay = false,
}: {
  task: Task;
  onEdit?: (task: Task) => void;
  overlay?: boolean;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: `task-${task.id}`, data: { type: "task", task } });

  const { isActive, elapsedSeconds } = useTimer();
  const running = isActive(task.id);

  const meta = PRIORITY_META[task.priority];
  const overdue = isOverdue(task.due_date, task.completed_at);
  const done = Boolean(task.completed_at);
  const trackedSeconds = (task.tracked_seconds ?? 0) + (running ? elapsedSeconds : 0);

  const style = overlay
    ? undefined
    : { transform: CSS.Transform.toString(transform), transition };

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      onClick={() => onEdit?.(task)}
      className={cn(
        "group cursor-grab touch-none rounded-lg border bg-card p-3 text-left shadow-sm transition active:cursor-grabbing hover:border-indigo-300",
        isDragging && "opacity-40",
        overlay && "rotate-2 cursor-grabbing shadow-xl ring-2 ring-indigo-300",
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span
          className={cn(
            "inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium",
            meta.badge,
          )}
        >
          <span className={cn("size-1.5 rounded-full", meta.dot)} />
          {meta.label}
        </span>

        {done ? (
          <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
            <Sparkles className="size-3" />
            {task.xp_awarded} XP
          </span>
        ) : null}
      </div>

      <p
        className={cn(
          "mt-2 text-sm font-medium",
          done && "text-muted-foreground line-through",
        )}
      >
        {task.title}
      </p>

      <div className="mt-2 flex items-center justify-between gap-2 text-xs">
        {done ? (
          <span className="inline-flex items-center gap-1 text-emerald-600">
            <CheckCircle2 className="size-3.5" />
            Concluída
          </span>
        ) : (
          <span
            className={cn(
              "inline-flex items-center gap-1",
              overdue ? "font-semibold text-red-600" : "text-muted-foreground",
            )}
          >
            <CalendarClock className="size-3.5" />
            {formatDueDate(task.due_date)}
            {overdue ? " · atrasada" : ""}
          </span>
        )}

        <div className="flex items-center gap-1.5">
          {trackedSeconds > 0 || running ? (
            <span
              className={cn(
                "inline-flex items-center gap-1 tabular-nums",
                running ? "font-medium text-indigo-600" : "text-muted-foreground",
              )}
            >
              <Timer className="size-3.5" />
              {formatDuration(trackedSeconds)}
            </span>
          ) : null}
          {!overlay ? <TimerButton taskId={task.id} /> : null}
        </div>
      </div>
    </div>
  );
}
