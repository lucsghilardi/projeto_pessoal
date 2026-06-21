<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimeReportController extends Controller
{
    /** Relatório de tempo do mês: por projeto, por dia, tendência e entregas. */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $month = (string) $request->query('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $start = Carbon::parse($month . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = TimeEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$start, $end])
            ->get(['project_id', 'duration_seconds', 'started_at']);

        $totalSeconds = (int) $entries->sum('duration_seconds');
        $secondsByProject = $entries->groupBy('project_id')->map->sum('duration_seconds');

        $completed = Task::query()
            ->where('user_id', $userId)
            ->whereBetween('completed_at', [$start, $end])
            ->get(['id', 'project_id', 'completed_at']);

        $completedByProject = $completed->groupBy('project_id')->map->count();

        // --- by_project (núcleo do "vale a pena manter o cliente") ---
        $projectIds = $secondsByProject->keys()->merge($completedByProject->keys())->unique();
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        $byProject = $projectIds
            ->map(function ($pid) use ($secondsByProject, $completedByProject, $projects, $totalSeconds) {
                $seconds = (int) ($secondsByProject[$pid] ?? 0);
                $done = (int) ($completedByProject[$pid] ?? 0);
                $project = $projects[$pid] ?? null;

                return [
                    'id' => (int) $pid,
                    'name' => $project?->name ?? 'Projeto removido',
                    'color' => $project?->color ?? '#94a3b8',
                    'seconds' => $seconds,
                    'tasks_completed' => $done,
                    'share_percent' => $totalSeconds > 0 ? round($seconds / $totalSeconds * 100, 1) : 0,
                    'avg_seconds_per_task' => $done > 0 ? (int) round($seconds / $done) : 0,
                ];
            })
            ->sortByDesc('seconds')
            ->values();

        // --- daily (todos os dias do mês, para barras contínuas) ---
        $secondsByDay = $entries
            ->groupBy(fn ($e) => $e->started_at->toDateString())
            ->map->sum('duration_seconds');

        $daily = [];
        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $key = $day->toDateString();
            $daily[] = ['date' => $key, 'seconds' => (int) ($secondsByDay[$key] ?? 0)];
        }

        // --- monthly_trend (últimos 6 meses) ---
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $ref = $start->copy()->subMonths($i);
            $seconds = (int) TimeEntry::query()
                ->where('user_id', $userId)
                ->whereBetween('started_at', [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()])
                ->sum('duration_seconds');
            $trend[] = ['month' => $ref->format('Y-m'), 'seconds' => $seconds];
        }

        // --- completed_tasks (tudo que foi feito no mês) ---
        $completedTasks = Task::query()
            ->where('user_id', $userId)
            ->whereBetween('completed_at', [$start, $end])
            ->with('project:id,name,color')
            ->withSum('timeEntries as seconds', 'duration_seconds')
            ->orderByDesc('completed_at')
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'project_name' => $task->project?->name,
                'project_color' => $task->project?->color,
                'completed_at' => $task->completed_at,
                'seconds' => (int) ($task->seconds ?? 0),
            ]);

        return response()->json([
            'month' => $month,
            'totals' => [
                'total_seconds' => $totalSeconds,
                'tasks_completed' => $completed->count(),
                'active_projects' => $secondsByProject->keys()->count(),
                'avg_seconds_per_task' => $completed->count() > 0
                    ? (int) round($totalSeconds / $completed->count())
                    : 0,
            ],
            'by_project' => $byProject,
            'daily' => $daily,
            'monthly_trend' => $trend,
            'completed_tasks' => $completedTasks,
        ]);
    }
}
