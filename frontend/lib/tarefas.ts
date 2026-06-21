import type { TaskPriority } from "@/types/Task";

export const PRIORITY_META: Record<
  TaskPriority,
  { label: string; xp: number; badge: string; dot: string }
> = {
  low: {
    label: "Baixa",
    xp: 10,
    badge: "border-zinc-200 bg-zinc-100 text-zinc-700",
    dot: "bg-zinc-400",
  },
  medium: {
    label: "Média",
    xp: 20,
    badge: "border-sky-200 bg-sky-50 text-sky-700",
    dot: "bg-sky-500",
  },
  high: {
    label: "Alta",
    xp: 35,
    badge: "border-amber-200 bg-amber-50 text-amber-700",
    dot: "bg-amber-500",
  },
  urgent: {
    label: "Urgente",
    xp: 50,
    badge: "border-red-200 bg-red-50 text-red-700",
    dot: "bg-red-500",
  },
};

export const PRIORITY_OPTIONS: TaskPriority[] = ["urgent", "high", "medium", "low"];

const PROJECT_COLORS = [
  "#6366f1",
  "#0ea5e9",
  "#10b981",
  "#f59e0b",
  "#ef4444",
  "#ec4899",
  "#8b5cf6",
  "#14b8a6",
];

export function projectColorChoices() {
  return PROJECT_COLORS;
}

function toLocalDate(value: string): Date {
  // Datas vêm como "YYYY-MM-DD" (date) ou ISO; normaliza para meia-noite local.
  return new Date(`${value.slice(0, 10)}T00:00:00`);
}

export function formatDueDate(value?: string | null): string {
  if (!value) {
    return "Sem prazo";
  }

  return new Intl.DateTimeFormat("pt-BR", {
    day: "2-digit",
    month: "short",
  }).format(toLocalDate(value));
}

/** Recebe um valor de data e devolve "YYYY-MM-DD" para inputs type=date. */
export function toDateInputValue(value?: string | null): string {
  if (!value) {
    return "";
  }

  return value.slice(0, 10);
}

export function isOverdue(due?: string | null, completedAt?: string | null): boolean {
  if (!due || completedAt) {
    return false;
  }

  const today = new Date();
  today.setHours(0, 0, 0, 0);

  return toLocalDate(due).getTime() < today.getTime();
}
