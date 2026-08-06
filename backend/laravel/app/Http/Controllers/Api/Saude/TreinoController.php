<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeTreino;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreinoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $treinos = SaudeTreino::query()
            ->where('user_id', $request->user()->id)
            ->with('exercicios')
            ->orderBy('posicao')
            ->orderBy('nome')
            ->get();

        return response()->json($treinos);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $this->validateData($request);

        $treino = SaudeTreino::create([
            'user_id' => $userId,
            'nome' => $data['nome'],
            'posicao' => $data['posicao']
                ?? ((int) SaudeTreino::where('user_id', $userId)->max('posicao') + 1),
        ]);

        return response()->json($treino->load('exercicios'), 201);
    }

    public function update(Request $request, SaudeTreino $treino): JsonResponse
    {
        $this->authorizeOwnership($request, $treino);

        $data = $this->validateData($request, $treino);

        $treino->update([
            'nome' => $data['nome'],
            'posicao' => $data['posicao'] ?? $treino->posicao,
        ]);

        return response()->json($treino->fresh()->load('exercicios'));
    }

    public function destroy(Request $request, SaudeTreino $treino): JsonResponse
    {
        $this->authorizeOwnership($request, $treino);

        $treino->delete();

        return response()->json(['message' => 'Treino removido com sucesso.']);
    }

    private function validateData(Request $request, ?SaudeTreino $ignore = null): array
    {
        return $request->validate([
            'nome' => [
                'required',
                'string',
                'max:60',
                Rule::unique('saude_treinos', 'nome')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'posicao' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function authorizeOwnership(Request $request, SaudeTreino $treino): void
    {
        abort_unless((int) $treino->user_id === (int) $request->user()->id, 403);
    }
}
