import { Flame, Sparkles, Star } from "lucide-react";

import { cn } from "@/lib/utils";
import type { GamificationSummary } from "@/types/Gamification";

export function GamificationPanel({
  summary,
  className,
}: {
  summary: GamificationSummary;
  className?: string;
}) {
  const progress = Math.min(
    100,
    Math.round((summary.xp_into_level / summary.xp_for_next_level) * 100),
  );

  return (
    <div
      className={cn(
        "rounded-2xl border bg-gradient-to-br from-indigo-50 via-background to-background p-4 shadow-sm",
        className,
      )}
    >
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-lg font-bold text-white shadow-sm">
            {summary.level}
          </div>
          <div className="min-w-0">
            <p className="text-xs font-medium text-muted-foreground">
              Nível {summary.level}
            </p>
            <p className="truncate text-base font-semibold">{summary.title}</p>
          </div>
        </div>

        <div className="flex items-center gap-4">
          <Stat
            icon={<Sparkles className="size-4 text-indigo-500" />}
            value={summary.total_xp.toLocaleString("pt-BR")}
            label="XP total"
          />
          <Stat
            icon={<Flame className="size-4 text-orange-500" />}
            value={`${summary.current_streak} ${summary.current_streak === 1 ? "dia" : "dias"}`}
            label="Sequência"
          />
          <Stat
            icon={<Star className="size-4 text-amber-500" />}
            value={summary.longest_streak.toString()}
            label="Recorde"
          />
        </div>
      </div>

      <div className="mt-4">
        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
          <span>Progresso do nível</span>
          <span>
            {summary.xp_into_level}/{summary.xp_for_next_level} XP
          </span>
        </div>
        <div className="h-2.5 w-full overflow-hidden rounded-full bg-indigo-100">
          <div
            className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-500"
            style={{ width: `${progress}%` }}
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
