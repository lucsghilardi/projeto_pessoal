import { Flame, Pill, Star } from "lucide-react";

import { cn } from "@/lib/utils";
import type { SaudeProgresso, SaudeStreak } from "@/types/Saude";

/** Painel de progresso do check-in diário + sequência de dias 100%. */
export function StreakCard({
  progresso,
  streak,
  className,
}: {
  progresso: SaudeProgresso;
  streak: SaudeStreak;
  className?: string;
}) {
  const percent =
    progresso.total > 0
      ? Math.min(100, Math.round((progresso.tomados / progresso.total) * 100))
      : 0;

  return (
    <div
      className={cn(
        "rounded-2xl border bg-gradient-to-br from-emerald-50 via-background to-background p-4 shadow-sm",
        className,
      )}
    >
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm">
            <Pill className="size-6" />
          </div>
          <div className="min-w-0">
            <p className="text-xs font-medium text-muted-foreground">
              Check-in de hoje
            </p>
            <p className="text-base font-semibold">
              {progresso.tomados}/{progresso.total} suplementos
            </p>
          </div>
        </div>

        <div className="flex items-center gap-4">
          <Stat
            icon={<Flame className="size-4 text-orange-500" />}
            value={`${streak.atual} ${streak.atual === 1 ? "dia" : "dias"}`}
            label="Sequência"
          />
          <Stat
            icon={<Star className="size-4 text-amber-500" />}
            value={streak.recorde.toString()}
            label="Recorde"
          />
        </div>
      </div>

      <div className="mt-4">
        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
          <span>Progresso do dia</span>
          <span>{percent}%</span>
        </div>
        <div className="h-2.5 w-full overflow-hidden rounded-full bg-emerald-100">
          <div
            className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-all duration-500"
            style={{ width: `${percent}%` }}
          />
        </div>
      </div>
    </div>
  );
}

function Stat({
  icon,
  value,
  label,
}: {
  icon: React.ReactNode;
  value: string;
  label: string;
}) {
  return (
    <div className="text-center">
      <div className="flex items-center justify-center gap-1 text-sm font-semibold">
        {icon}
        {value}
      </div>
      <p className="text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}
