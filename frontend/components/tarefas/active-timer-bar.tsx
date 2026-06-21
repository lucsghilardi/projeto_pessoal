"use client";

import { Square, Timer } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";
import { formatClock } from "@/lib/format";

import { useTimer } from "./timer-context";

export function ActiveTimerBar() {
  const { active, elapsedSeconds, stopping, stop } = useTimer();

  if (!active) {
    return null;
  }

  return (
    <div className="no-print mb-4 flex items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5">
      <div className="flex min-w-0 items-center gap-3">
        <span className="relative flex size-2.5 shrink-0">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-indigo-400 opacity-75" />
          <span className="relative inline-flex size-2.5 rounded-full bg-indigo-500" />
        </span>
        <Timer className="size-4 shrink-0 text-indigo-600" />
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">
            {active.task?.title ?? "Tarefa"}
          </p>
          {active.project ? (
            <p className="truncate text-xs text-muted-foreground">{active.project.name}</p>
          ) : null}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-3">
        <span className="font-mono text-base font-semibold tabular-nums text-indigo-700">
          {formatClock(elapsedSeconds)}
        </span>
        <Button size="sm" variant="destructive" onClick={() => stop()} disabled={stopping}>
          {stopping ? <Spinner /> : <Square className="size-4" />}
          Parar
        </Button>
      </div>
    </div>
  );
}
