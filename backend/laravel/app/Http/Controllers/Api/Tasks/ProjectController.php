<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /** Colunas criadas automaticamente em todo projeto novo. */
    private const DEFAULT_COLUMNS = [
        ['name' => 'A fazer', 'position' => 0, 'is_done_column' => false],
        ['name' => 'Fazendo', 'position' => 1, 'is_done_column' => false],
        ['name' => 'Em revisão', 'position' => 2, 'is_done_column' => false],
        ['name' => 'Concluído', 'position' => 3, 'is_done_column' => true],
    ];

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $projects = Project::query()
            ->where('user_id', $userId)
            ->withCount([
                'tasks',
                'tasks as completed_count' => fn ($q) => $q->whereNotNull('completed_at'),
                'tasks as overdue_count' => fn ($q) => $q
                    ->whereNull('completed_at')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', today()),
            ])
            ->withSum('tasks as xp', 'xp_awarded')
            ->withSum('timeEntries as tracked_seconds', 'duration_seconds')
            ->orderBy('name')
            ->get();

        return response()->json($projects);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $project->load([
            'columns',
            'columns.tasks' => fn ($q) => $q->withSum('timeEntries as tracked_seconds', 'duration_seconds'),
        ]);

        return response()->json($project);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $this->validateData($request);

        $project = DB::transaction(function () use ($userId, $data) {
            $project = Project::create([
                'user_id' => $userId,
                'name' => $data['name'],
                'color' => $data['color'] ?? '#6366f1',
                'description' => $data['description'] ?? null,
            ]);

            foreach (self::DEFAULT_COLUMNS as $column) {
                $project->columns()->create([
                    'user_id' => $userId,
                    'name' => $column['name'],
                    'position' => $column['position'],
                    'is_done_column' => $column['is_done_column'],
                ]);
            }

            return $project;
        });

        $project->load([
            'columns',
            'columns.tasks' => fn ($q) => $q->withSum('timeEntries as tracked_seconds', 'duration_seconds'),
        ]);

        return response()->json($project, 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $data = $this->validateData($request, $project);

        $project->update([
            'name' => $data['name'],
            'color' => $data['color'] ?? $project->color,
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($project->fresh());
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $project->delete();

        return response()->json(['message' => 'Projeto removido com sucesso.']);
    }

    private function validateData(Request $request, ?Project $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
        ]);
    }

    private function authorizeOwnership(Request $request, Project $project): void
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 403);
    }
}
