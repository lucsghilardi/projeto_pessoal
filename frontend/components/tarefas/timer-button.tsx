"use client";

import { Pause, Play } from "lucide-react";

import { cn } from "@/lib/utils";

import { useTimer } from "./timer-context";

export function TimerButton({ taskId }: { taskId: number }) {
  const { isActive, start, stop, starting, stopping } = useTimer();
  const running = isActive(taskId);

  function handleClick(event: React.MouseEvent) {
    event.stopPropagation();
    if (running) {
      void stop();
    } else {
      void start(taskId);
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      onPointerDown={(event) => event.stopPropagation()}
      disabled={starting || stopping}
      aria-label={running ? "Parar cronômetro" : "Iniciar cronômetro"}
      className={cn(
        "inline-flex size-6 items-center justify-center rounded-md border transition disabled:opacity-50",
        running
          ? "border-indigo-300 bg-indigo-100 text-indigo-700 hover:bg-indigo-200"
          : "border-transparent text-muted-foreground hover:border-border hover:bg-muted hover:text-foreground",
      )}
    >
      {running ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
    </button>
  );
}
