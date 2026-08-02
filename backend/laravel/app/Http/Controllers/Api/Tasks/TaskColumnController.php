<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TaskColumn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskColumnController extends Controller
{
    /** Todas as colunas do usuário, para montar o mapa projeto -> colunas no relatório. */
    public function index(Request $request): JsonResponse
    {
        $columns = TaskColumn::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('project_id')
            ->orderBy('position')
            ->get(['id', 'project_id', 'name', 'position', 'is_done_column', 'color']);

        return response()->json($columns);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProjectOwnership($request, $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_done_column' => ['boolean'],
        ]);

        $position = (int) $project->columns()->max('position') + 1;

        $column = $project->columns()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'is_done_column' => $data['is_done_column'] ?? false,
            'position' => $position,
        ]);

        return response()->json($column, 201);
    }

    public function update(Request $request, TaskColumn $taskColumn): JsonResponse
    {
        $this->authorizeOwnership($request, $taskColumn);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_done_column' => ['boolean'],
        ]);

        $taskColumn->update([
            'name' => $data['name'],
            'color' => $data['color'] ?? null,
            'is_done_column' => $data['is_done_column'] ?? $taskColumn->is_done_column,
        ]);

        return response()->json($taskColumn->fresh());
    }

    public function destroy(Request $request, TaskColumn $taskColumn): JsonResponse
    {
        $this->authorizeOwnership($request, $taskColumn);

        if ($taskColumn->tasks()->exists()) {
            return response()->json([
                'message' => 'Mova ou exclua as tarefas desta coluna antes de removê-la.',
            ], 422);
        }

        $taskColumn->delete();

        return response()->json(['message' => 'Coluna removida com sucesso.']);
    }

    /** Reordena as colunas de um projeto a partir de uma lista de ids. */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['integer'],
        ]);

        $columns = TaskColumn::query()
            ->where('user_id', $userId)
            ->whereIn('id', $data['columns'])
            ->get();

        abort_unless($columns->count() === count($data['columns']), 403);

        foreach ($data['columns'] as $position => $id) {
            $columns->firstWhere('id', $id)?->update(['position' => $position]);
        }

        return response()->json(['message' => 'Colunas reordenadas.']);
    }

    private function authorizeProjectOwnership(Request $request, Project $project): void
    {
        abort_unless((int) $project->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeOwnership(Request $request, TaskColumn $column): void
    {
        abort_unless((int) $column->user_id === (int) $request->user()->id, 403);
    }
}
