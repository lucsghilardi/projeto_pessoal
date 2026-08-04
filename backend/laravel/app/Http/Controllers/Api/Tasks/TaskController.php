<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TimeEntry;
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

    /** Deleção em massa, escopada pelo usuário: ids alheios são ignorados. Não estorna XP. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $deleted = DB::transaction(fn () => Task::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids'])
            ->delete());

        return response()->json([
            'message' => "{$deleted} tarefa(s) removida(s) com sucesso.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Persiste o arrastar-soltar (troca de coluna e/ou posição) e também a
     * reatribuição da tarefa a outro projeto, quando vem `project_id`.
     * Dispara ou estorna a gamificação conforme a coluna de destino seja "concluído".
     */
    public function move(Request $request, Task $task, GamificationService $gamification): JsonResponse
    {
        $this->authorizeOwnership($request, $task);

        $userId = $request->user()->id;

        // Sem `project_id`, o destino é o projeto atual — mantém o contrato do kanban.
        $targetProjectId = $request->filled('project_id')
            ? (int) $request->input('project_id')
            : (int) $task->project_id;

        $data = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('user_id', $userId),
            ],
            'task_column_id' => [
                'required_without:project_id',
                'nullable',
                'integer',
                Rule::exists('task_columns', 'id')
                    ->where('user_id', $userId)
                    ->where('project_id', $targetProjectId),
            ],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $wasCompleted = $task->completed_at !== null;
        $previousXp = (int) $task->xp_awarded;

        $destination = $request->filled('task_column_id')
            ? TaskColumn::findOrFail($data['task_column_id'])
            : $this->resolveDestinationColumn($targetProjectId, $userId, $wasCompleted);

        $sourceColumnId = (int) $task->task_column_id;
        $sourceProjectId = (int) $task->project_id;
        // A coluna validada é a fonte da verdade do projeto de destino.
        $destProjectId = (int) $destination->project_id;

        // Sem posição explícita, entra no fim da coluna de destino.
        $target = $data['position'] ?? (int) Task::query()
            ->where('task_column_id', $destination->id)
            ->where('id', '!=', $task->id)
            ->count();

        DB::transaction(function () use ($task, $destination, $sourceColumnId, $sourceProjectId, $destProjectId, $target) {
            // Abre espaço na coluna de destino e posiciona a tarefa.
            Task::query()
                ->where('task_column_id', $destination->id)
                ->where('id', '!=', $task->id)
                ->where('position', '>=', $target)
                ->increment('position');

            $task->project_id = $destProjectId;
            $task->task_column_id = $destination->id;
            $task->position = $target;
            $task->save();

            // `time_entries.project_id` é cópia denormalizada de `tasks.project_id`
            // (ver TimeEntryController): sem repropagar, os relatórios por projeto
            // ficariam com horas num projeto e tarefas concluídas noutro.
            if ($sourceProjectId !== $destProjectId) {
                TimeEntry::query()
                    ->where('user_id', $task->user_id)
                    ->where('task_id', $task->id)
                    ->update(['project_id' => $destProjectId]);
            }

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

    /**
     * Escolhe a coluna de destino ao reatribuir a tarefa a outro projeto.
     * Preserva o estado de conclusão para que trocar de projeto, por si só,
     * nunca conceda nem estorne XP — `$wantsDone` é a mesma variável que
     * dirige o branch de gamificação em move().
     */
    private function resolveDestinationColumn(int $projectId, int $userId, bool $wantsDone): TaskColumn
    {
        $columns = TaskColumn::query()
            ->where('user_id', $userId)
            ->where('project_id', $projectId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        abort_if($columns->isEmpty(), 422, 'O projeto de destino não possui colunas.');

        return $wantsDone
            ? ($columns->firstWhere('is_done_column', true) ?? $columns->last())
            : ($columns->firstWhere('is_done_column', false) ?? $columns->first());
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
