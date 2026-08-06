<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeTreinoSessao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TreinoSessaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $sessoes = SaudeTreinoSessao::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['de'] ?? null, fn ($q, $de) => $q->where('data', '>=', $de))
            ->when($filters['ate'] ?? null, fn ($q, $ate) => $q->where('data', '<=', $ate))
            ->with('treino:id,nome')
            ->orderByDesc('data')
            ->get();

        return response()->json($sessoes);
    }

    /** Registra o treino do dia (um por dia — trocar de treino atualiza a linha). */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'treino_id' => [
                'required',
                'integer',
                Rule::exists('saude_treinos', 'id')->where('user_id', $userId),
            ],
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $dia = $data['data'] ?? now((string) config('saude.timezone'))->toDateString();

        $sessao = SaudeTreinoSessao::updateOrCreate(
            ['user_id' => $userId, 'data' => $dia],
            ['treino_id' => $data['treino_id']],
        );

        return response()->json($sessao->load('treino:id,nome'), 201);
    }

    public function destroy(Request $request, SaudeTreinoSessao $sessao): JsonResponse
    {
        abort_unless((int) $sessao->user_id === (int) $request->user()->id, 403);

        $sessao->delete();

        return response()->json(['message' => 'Sessão de treino removida.']);
    }
}
