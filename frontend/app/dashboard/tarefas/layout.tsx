"use client";

import { ActiveTimerBar } from "@/components/tarefas/active-timer-bar";
import { TimerProvider } from "@/components/tarefas/timer-context";

export default function TarefasLayout({ children }: { children: React.ReactNode }) {
  return (
    <TimerProvider>
      <ActiveTimerBar />
      {children}
    </TimerProvider>
  );
}
