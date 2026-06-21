<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $tasks = Task::query()
            ->where('user_id', $userId)
            ->when(
                $request->query('project_id'),
                fn ($q, $projectId) => $q->where('project_id', $projectId),
            )
            ->with(['project:id,name,color', 'column:id,name,is_done_column'])
            ->withSum('timeEntries as tracked_seconds', 'duration_seconds')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();

        return response()->json($tasks);
    }

    public function store(Request $request, GamificationService $gamification): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where('user_id', $userId),
            ],
            'task_column_id' => [
                'required',
                'integer',
                Rule::exists('task_columns', 'id')
                    ->where('user_id', $userId)
                    ->where('project_id', $request->input('project_id')),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $position = (int) Task::query()
            ->where('task_column_id', $data['task_column_id'])
            ->max('position') + 1;

        $task = Task::create([
            'user_id' => $userId,
            'project_id' => $data['project_id'],
            'task_column_id' => $data['task_column_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'position' => $position,
        ]);

        // Tarefa criada já dentro de uma coluna de "concluído" pontua imediatamente.
        $column = TaskColumn::find($data['task_column_id']);
        if ($column?->is_done_column) {
            $gamification->complete($task);
        }

        $task->load(['project:id,name,color', 'column:id,name,is_done_column']);

        return response()->json($task, 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $task->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
        ]);

        $task->load(['project:id,name,color', 'column:id,name,is_done_column']);

        return response()->json($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $task->delete();

        return response()->json(['message' => 'Tarefa removida com sucesso.']);
    }

    /**
     * Persiste o arrastar-soltar: troca de coluna e/ou posição, e dispara ou
     * estorna a gamificação conforme a coluna de destino seja "concluído".
     */
    public function move(Request $request, Task $task, GamificationService $gamification): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $userId = $request->user()->id;

        $data = $request->validate([
            'task_column_id' => [
                'required',
                'integer',
                Rule::exists('task_columns', 'id')
                    ->where('user_id', $userId)
                    ->where('project_id', $task->project_id),
            ],
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $destination = TaskColumn::findOrFail($data['task_column_id']);
        $sourceColumnId = (int) $task->task_column_id;
        $target = (int) $data['position'];
        $wasCompleted = $task->completed_at !== null;
        $previousXp = (int) $task->xp_awarded;

        DB::transaction(function () use ($task, $destination, $sourceColumnId, $target) {
            // Abre espaço na coluna de destino e posiciona a tarefa.
            Task::query()
                ->where('task_column_id', $destination->id)
                ->where('id', '!=', $task->id)
                ->where('position', '>=', $target)
                ->increment('position');

            $task->task_column_id = $destination->id;
            $task->position = $target;
            $task->save();

            $this->resequence($destination->id);

            if ($sourceColumnId !== (int) $destination->id) {
                $this->resequence($sourceColumnId);
            }
        });

        if ($destination->is_done_column && ! $wasCompleted) {
            $gamification->complete($task);
        } elseif (! $destination->is_done_column && $wasCompleted) {
            $gamification->revert($task);
        }

        $task->load(['project:id,name,color', 'column:id,name,is_done_column']);

        $xpGained = (int) $task->xp_awarded - ($wasCompleted ? $previousXp : 0);

        return response()->json([
            'task' => $task,
            'xp_gained' => $xpGained,
            'gamification' => $gamification->summary($userId),
        ]);
    }

    /** Renumera as posições de uma coluna em sequência (0..n). */
    private function resequence(int $columnId): void
    {
        $tasks = Task::query()
            ->where('task_column_id', $columnId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($tasks as $index => $task) {
            if ((int) $task->position !== $index) {
                $task->update(['position' => $index]);
            }
        }
    }

    private function authorizeOwnership(Request $request, Task $task): void
    {
        abort_unless((int) $task->user_id === (int) $request->user()->id, 403);
    }
}
