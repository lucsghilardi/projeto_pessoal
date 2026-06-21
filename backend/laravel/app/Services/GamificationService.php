<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\UserAchievement;
use App\Models\UserStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GamificationService
{
    /** XP base por prioridade de tarefa. */
    private const PRIORITY_XP = [
        'low' => 10,
        'medium' => 20,
        'high' => 35,
        'urgent' => 50,
    ];

    /** Títulos por faixa de nível (chave = nível mínimo). */
    private const TITLES = [
        1 => 'Recruta',
        3 => 'Aventureiro',
        5 => 'Veterano',
        7 => 'Mestre',
        10 => 'Lenda',
    ];

    /**
     * Marca a tarefa como concluída, concede XP (com bônus de pontualidade e
     * multiplicador de streak), atualiza nível/streak e avalia conquistas.
     */
    public function complete(Task $task): void
    {
        if ($task->completed_at !== null) {
            return;
        }

        $stat = $this->statsFor((int) $task->user_id);
        $today = Carbon::today();

        $this->touchStreak($stat, $today);

        $base = self::PRIORITY_XP[$task->priority] ?? self::PRIORITY_XP['medium'];
        $punctuality = $this->punctualityMultiplier($task->due_date, $today);
        $streakMultiplier = 1 + min($stat->current_streak, 10) * 0.05;

        $xp = (int) round($base * $punctuality * $streakMultiplier);

        $task->forceFill([
            'completed_at' => now(),
            'xp_awarded' => $xp,
        ])->save();

        $stat->total_xp += $xp;
        $stat->level = $this->levelFromXp($stat->total_xp);
        $stat->save();

        $this->evaluateAchievements((int) $task->user_id, $stat);
    }

    /**
     * Desfaz a conclusão: estorna o XP e recalcula o nível. O streak não regride.
     */
    public function revert(Task $task): void
    {
        if ($task->completed_at === null) {
            return;
        }

        $stat = $this->statsFor((int) $task->user_id);
        $stat->total_xp = max(0, $stat->total_xp - (int) $task->xp_awarded);
        $stat->level = $this->levelFromXp($stat->total_xp);
        $stat->save();

        $task->forceFill([
            'completed_at' => null,
            'xp_awarded' => 0,
        ])->save();
    }

    public function statsFor(int $userId): UserStat
    {
        return UserStat::firstOrCreate(['user_id' => $userId]);
    }

    /**
     * Resumo consumido pelo GamificationController (painel + conquistas + ranking).
     */
    public function summary(int $userId): array
    {
        $stat = $this->statsFor($userId);

        $level = $stat->level;
        $currentBase = $this->xpForLevel($level);
        $nextBase = $this->xpForLevel($level + 1);

        $definitions = config('achievements');
        $unlocked = UserAchievement::query()
            ->where('user_id', $userId)
            ->pluck('unlocked_at', 'achievement_key');

        $achievements = collect($definitions)
            ->map(fn (array $def, string $key) => [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'icon' => $def['icon'],
                'unlocked' => $unlocked->has($key),
                'unlocked_at' => $unlocked->get($key),
            ])
            ->values();

        $ranking = Project::query()
            ->where('projects.user_id', $userId)
            ->leftJoin('tasks', 'tasks.project_id', '=', 'projects.id')
            ->groupBy('projects.id', 'projects.name', 'projects.color')
            ->orderByRaw('COALESCE(SUM(tasks.xp_awarded), 0) DESC')
            ->get([
                'projects.id',
                'projects.name',
                'projects.color',
                DB::raw('COALESCE(SUM(tasks.xp_awarded), 0)::int as xp'),
                DB::raw('COUNT(tasks.id) FILTER (WHERE tasks.completed_at IS NOT NULL)::int as completed_tasks'),
            ]);

        return [
            'total_xp' => (int) $stat->total_xp,
            'level' => $level,
            'title' => $this->titleForLevel($level),
            'current_streak' => (int) $stat->current_streak,
            'longest_streak' => (int) $stat->longest_streak,
            'last_completed_date' => optional($stat->last_completed_date)->toDateString(),
            'xp_into_level' => (int) $stat->total_xp - $currentBase,
            'xp_for_next_level' => max(1, $nextBase - $currentBase),
            'next_level_total_xp' => $nextBase,
            'achievements' => $achievements,
            'project_ranking' => $ranking,
        ];
    }

    /** XP acumulado necessário para alcançar um nível (curva quadrática). */
    public function xpForLevel(int $level): int
    {
        $level = max(1, $level);

        return 50 * ($level - 1) ** 2;
    }

    public function levelFromXp(int $xp): int
    {
        return (int) floor(sqrt(max(0, $xp) / 50)) + 1;
    }

    private function titleForLevel(int $level): string
    {
        $title = self::TITLES[1];

        foreach (self::TITLES as $minLevel => $label) {
            if ($level >= $minLevel) {
                $title = $label;
            }
        }

        return $title;
    }

    private function punctualityMultiplier(?Carbon $dueDate, Carbon $today): float
    {
        if ($dueDate === null) {
            return 1.0;
        }

        $due = $dueDate->copy()->startOfDay();

        if ($today->lessThan($due)) {
            return 1.5; // concluída antes do prazo
        }

        if ($today->equalTo($due)) {
            return 1.2; // concluída no dia
        }

        return 1.0; // atrasada
    }

    private function touchStreak(UserStat $stat, Carbon $today): void
    {
        $last = $stat->last_completed_date
            ? $stat->last_completed_date->copy()->startOfDay()
            : null;

        if ($last && $last->equalTo($today)) {
            return; // já concluiu algo hoje, streak inalterado
        }

        if ($last && $last->equalTo($today->copy()->subDay())) {
            $stat->current_streak += 1;
        } else {
            $stat->current_streak = 1;
        }

        $stat->longest_streak = max($stat->longest_streak, $stat->current_streak);
        $stat->last_completed_date = $today;
    }

    private function evaluateAchievements(int $userId, UserStat $stat): void
    {
        $alreadyUnlocked = UserAchievement::query()
            ->where('user_id', $userId)
            ->pluck('achievement_key')
            ->all();

        $completedCount = Task::query()
            ->where('user_id', $userId)
            ->whereNotNull('completed_at')
            ->count();

        $todayCount = Task::query()
            ->where('user_id', $userId)
            ->whereDate('completed_at', Carbon::today())
            ->count();

        $urgentOnTime = Task::query()
            ->where('user_id', $userId)
            ->where('priority', 'urgent')
            ->whereNotNull('completed_at')
            ->whereNotNull('due_date')
            ->whereRaw('completed_at::date <= due_date')
            ->count();

        $conditions = [
            'first_task' => $completedCount >= 1,
            'ten_tasks' => $completedCount >= 10,
            'fifty_tasks' => $completedCount >= 50,
            'maratonista' => $todayCount >= 10,
            'domador_urgencias' => $urgentOnTime >= 5,
            'semana_perfeita' => $stat->current_streak >= 7,
            'nivel_5' => $stat->level >= 5,
            'nivel_10' => $stat->level >= 10,
        ];

        $newRows = [];

        foreach ($conditions as $key => $met) {
            if ($met && ! in_array($key, $alreadyUnlocked, true)) {
                $newRows[] = [
                    'user_id' => $userId,
                    'achievement_key' => $key,
                    'unlocked_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($newRows !== []) {
            UserAchievement::insert($newRows);
        }
    }
}
