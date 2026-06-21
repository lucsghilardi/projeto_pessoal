<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoardController extends Controller
{
    /** Agregados cross-projeto para a visão geral de tarefas. */
    public function overview(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $base = fn () => Task::query()->where('user_id', $userId);

        $total = $base()->count();
        $completed = $base()->whereNotNull('completed_at')->count();
        $overdue = $base()
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->count();

        return response()->json([
            'projects' => Project::query()->where('user_id', $userId)->count(),
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'pending_tasks' => $total - $completed,
            'overdue_tasks' => $overdue,
        ]);
    }
}
