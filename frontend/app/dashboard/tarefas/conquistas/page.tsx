"use client";

import { useEffect, useState } from "react";
import { Lock, Trophy } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { AchievementIcon } from "@/components/tarefas/icons";
import { GamificationPanel } from "@/components/tarefas/gamification-panel";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import { getGamification } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { GamificationSummary } from "@/types/Gamification";

const MEDALS = ["🥇", "🥈", "🥉"];

export default function ConquistasPage() {
  const [summary, setSummary] = useState<GamificationSummary | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let mounted = true;

    async function load() {
      try {
        const data = await getGamification();
        if (mounted) {
          setSummary(data);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível carregar as conquistas.",
        );
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    }

    load();

    return () => {
      mounted = false;
    };
  }, []);

  if (loading) {
    return <DashboardPageLoader />;
  }

  if (!summary) {
    return null;
  }

  const unlockedCount = summary.achievements.filter((a) => a.unlocked).length;

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Conquistas"
        description="Suas medalhas, sequência e o ranking de XP entre projetos."
      />

      <GamificationPanel summary={summary} />

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Trophy className="size-4 text-amber-500" />
            Medalhas
            <span className="text-sm font-normal text-muted-foreground">
              {unlockedCount}/{summary.achievements.length}
            </span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {summary.achievements.map((achievement) => (
              <div
                key={achievement.key}
                className={cn(
                  "flex flex-col items-center gap-2 rounded-xl border p-4 text-center transition",
                  achievement.unlocked
                    ? "border-amber-200 bg-amber-50"
                    : "border-dashed bg-muted/30 opacity-70",
                )}
              >
                <div
                  className={cn(
                    "flex size-12 items-center justify-center rounded-full",
                    achievement.unlocked
                      ? "bg-amber-100 text-amber-600"
                      : "bg-muted text-muted-foreground",
                  )}
                >
                  {achievement.unlocked ? (
                    <AchievementIcon name={achievement.icon} className="size-6" />
                  ) : (
                    <Lock className="size-5" />
                  )}
                </div>
                <p className="text-sm font-semibold">{achievement.label}</p>
                <p className="text-xs text-muted-foreground">{achievement.description}</p>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Ranking de projetos</CardTitle>
        </CardHeader>
        <CardContent>
          {summary.project_ranking.length === 0 ? (
            <p className="py-4 text-center text-sm text-muted-foreground">
              Conclua tarefas para ver seus projetos no ranking.
            </p>
          ) : (
            <ul className="space-y-2">
              {summary.project_ranking.map((entry, index) => (
                <li
                  key={entry.id}
                  className="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2.5"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <span className="w-6 text-center text-sm font-semibold">
                      {MEDALS[index] ?? index + 1}
                    </span>
                    <span
                      className="size-3 shrink-0 rounded-full"
                      style={{ backgroundColor: entry.color }}
                    />
                    <span className="truncate font-medium">{entry.name}</span>
                  </div>
                  <div className="flex shrink-0 items-center gap-4 text-sm">
                    <span className="text-muted-foreground">
                      {entry.completed_tasks} concluída(s)
                    </span>
                    <span className="font-semibold text-indigo-600">{entry.xp} XP</span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
