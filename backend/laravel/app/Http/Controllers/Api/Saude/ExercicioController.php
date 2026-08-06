<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeExercicio;
use App\Models\SaudeTreino;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExercicioController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'treino_id' => [
                'required',
                'integer',
                Rule::exists('saude_treinos', 'id')->where('user_id', $userId),
            ],
            'nome' => ['required', 'string', 'max:120'],
            'series' => ['nullable', 'integer', 'min:1', 'max:50'],
            'repeticoes' => ['nullable', 'string', 'max:30'],
            'carga' => ['nullable', 'string', 'max:30'],
            'duracao_min' => ['nullable', 'integer', 'min:1', 'max:600'],
            'intensidade' => ['nullable', 'string', 'max:30'],
            'observacoes' => ['nullable', 'string', 'max:255'],
        ]);

        $exercicio = SaudeExercicio::create([
            'user_id' => $userId,
            'treino_id' => $data['treino_id'],
            'nome' => $data['nome'],
            'series' => $data['series'] ?? null,
            'repeticoes' => $data['repeticoes'] ?? null,
            'carga' => $data['carga'] ?? null,
            'duracao_min' => $data['duracao_min'] ?? null,
            'intensidade' => $data['intensidade'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'posicao' => (int) SaudeExercicio::where('treino_id', $data['treino_id'])->max('posicao') + 1,
        ]);

        return response()->json($exercicio, 201);
    }

    public function update(Request $request, SaudeExercicio $exercicio): JsonResponse
    {
        $this->authorizeOwnership($request, $exercicio);

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'series' => ['nullable', 'integer', 'min:1', 'max:50'],
            'repeticoes' => ['nullable', 'string', 'max:30'],
            'carga' => ['nullable', 'string', 'max:30'],
            'duracao_min' => ['nullable', 'integer', 'min:1', 'max:600'],
            'intensidade' => ['nullable', 'string', 'max:30'],
            'observacoes' => ['nullable', 'string', 'max:255'],
        ]);

        $exercicio->update([
            'nome' => $data['nome'],
            'series' => $data['series'] ?? null,
            'repeticoes' => $data['repeticoes'] ?? null,
            'carga' => $data['carga'] ?? null,
            'duracao_min' => $data['duracao_min'] ?? null,
            'intensidade' => $data['intensidade'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        return response()->json($exercicio->fresh());
    }

    public function destroy(Request $request, SaudeExercicio $exercicio): JsonResponse
    {
        $this->authorizeOwnership($request, $exercicio);

        $exercicio->delete();

        return response()->json(['message' => 'Exercício removido com sucesso.']);
    }

    /** Reordena os exercícios de um treino a partir de uma lista de ids. */
    public function reorder(Request $request, SaudeTreino $treino): JsonResponse
    {
        abort_unless((int) $treino->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'exercicios' => ['required', 'array', 'min:1'],
            'exercicios.*' => ['integer'],
        ]);

        $exercicios = SaudeExercicio::query()
            ->where('treino_id', $treino->id)
            ->whereIn('id', $data['exercicios'])
            ->get();

        abort_unless($exercicios->count() === count($data['exercicios']), 403);

        foreach ($data['exercicios'] as $posicao => $id) {
            $exercicios->firstWhere('id', $id)?->update(['posicao' => $posicao]);
        }

        return response()->json(['message' => 'Exercícios reordenados.']);
    }

    private function authorizeOwnership(Request $request, SaudeExercicio $exercicio): void
    {
        abort_unless((int) $exercicio->user_id === (int) $request->user()->id, 403);
    }
}
