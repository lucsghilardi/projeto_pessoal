<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class TimeEntryController extends Controller
{
    private const WITH = ['task:id,title,project_id', 'project:id,name,color'];

    /** Cronômetro em execução do usuário, ou 204 quando não há nenhum. */
    public function active(Request $request): JsonResponse
    {
        $entry = TimeEntry::query()
            ->with(self::WITH)
            ->where('user_id', $request->user()->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        // response()->json(null) serializaria como "{}" (peculiaridade do Symfony),
        // o que o front interpretaria como um cronômetro ativo. 204 evita isso.
        if (! $entry) {
            return response()->json(null, 204);
        }

        return response()->json($entry);
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $entries = TimeEntry::query()
            ->where('user_id', $userId)
            ->when($request->query('task_id'), fn ($q, $id) => $q->where('task_id', $id))
            ->when($request->query('project_id'), fn ($q, $id) => $q->where('project_id', $id))
            ->orderByDesc('started_at')
            ->get();

        return response()->json($entries);
    }

    /** Inicia o cronômetro numa tarefa, parando qualquer outro em execução. */
    public function start(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'task_id' => [
                'required',
                'integer',
                Rule::exists('tasks', 'id')->where('user_id', $userId),
            ],
        ]);

        $task = Task::findOrFail($data['task_id']);

        $this->stopRunningFor($userId);

        $entry = TimeEntry::create([
            'user_id' => $userId,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'started_at' => now(),
            'ended_at' => null,
            'duration_seconds' => 0,
        ]);

        return response()->json($entry->load(self::WITH), 201);
    }

    public function stop(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeOwnership($request, $timeEntry);

        if ($timeEntry->isRunning()) {
            $timeEntry->ended_at = now();
            $timeEntry->duration_seconds = $timeEntry->started_at->diffInSeconds($timeEntry->ended_at);
            $timeEntry->save();
        }

        return response()->json($timeEntry->load(self::WITH));
    }

    /** Lançamento manual de tempo (data + duração em minutos). */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'task_id' => [
                'required',
                'integer',
                Rule::exists('tasks', 'id')->where('user_id', $userId),
            ],
            'started_at' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $task = Task::findOrFail($data['task_id']);
        $startedAt = Carbon::parse($data['started_at'])->startOfDay();
        $minutes = (int) $data['minutes'];

        $entry = TimeEntry::create([
            'user_id' => $userId,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes($minutes),
            'duration_seconds' => $minutes * 60,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json($entry->load(self::WITH), 201);
    }

    public function update(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeOwnership($request, $timeEntry);

        $data = $request->validate([
            'started_at' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $startedAt = Carbon::parse($data['started_at'])->startOfDay();
        $minutes = (int) $data['minutes'];

        $timeEntry->update([
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes($minutes),
            'duration_seconds' => $minutes * 60,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json($timeEntry->fresh()->load(self::WITH));
    }

    public function destroy(Request $request, TimeEntry $timeEntry): JsonResponse
    {
        $this->authorizeOwnership($request, $timeEntry);

        $timeEntry->delete();

        return response()->json(['message' => 'Registro de tempo removido.']);
    }

    private function stopRunningFor(int $userId): void
    {
        TimeEntry::query()
            ->where('user_id', $userId)
            ->whereNull('ended_at')
            ->get()
            ->each(function (TimeEntry $entry) {
                $entry->ended_at = now();
                $entry->duration_seconds = $entry->started_at->diffInSeconds($entry->ended_at);
                $entry->save();
            });
    }

    private function authorizeOwnership(Request $request, TimeEntry $timeEntry): void
    {
        abort_unless((int) $timeEntry->user_id === (int) $request->user()->id, 403);
    }
}
