<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeCardioSessao;
use App\Services\Saude\SaudeCardioService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CardioSessaoController extends Controller
{
    private const MODALIDADES = ['corrida_rua', 'esteira', 'bike', 'eliptico', 'caminhada', 'outro'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $sessoes = SaudeCardioSessao::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['de'] ?? null, fn ($q, $de) => $q->where('data', '>=', $de))
            ->when($filters['ate'] ?? null, fn ($q, $ate) => $q->where('data', '<=', $ate))
            ->with('treino:id,nome')
            ->orderByDesc('data')
            ->orderByDesc('horario')
            ->orderByDesc('id')
            ->get();

        return response()->json($sessoes);
    }

    /** Totais do período; sem filtro, os últimos 7 dias. */
    public function resumo(Request $request, SaudeCardioService $cardio): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $ate = $filters['ate'] ?? now((string) config('saude.timezone'))->toDateString();
        $de = $filters['de'] ?? CarbonImmutable::parse($ate)->subDays(6)->toDateString();

        return response()->json([
            'de' => $de,
            'ate' => $ate,
            ...$cardio->resumo($request->user()->id, $de, $ate),
        ]);
    }

    /** Lançamento manual (o import do Garmin escreve pelo GarminImportService). */
    public function store(Request $request): JsonResponse
    {
        $sessao = SaudeCardioSessao::create([
            ...$this->validateData($request),
            'user_id' => $request->user()->id,
            'origem' => 'manual',
        ]);

        return response()->json($sessao->load('treino:id,nome'), 201);
    }

    /** Edição livre — inclusive para corrigir o que veio do relógio. */
    public function update(Request $request, SaudeCardioSessao $cardio): JsonResponse
    {
        $this->authorizeOwnership($request, $cardio);

        // `origem` e `garmin_activity_id` são rastro da importação: não se editam.
        $cardio->update($this->validateData($request));

        return response()->json($cardio->fresh()->load('treino:id,nome'));
    }

    public function destroy(Request $request, SaudeCardioSessao $cardio): JsonResponse
    {
        $this->authorizeOwnership($request, $cardio);

        $cardio->delete();

        return response()->json(['message' => 'Sessão de cardio removida.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'data' => ['required', 'date_format:Y-m-d'],
            'horario' => ['nullable', 'date_format:H:i'],
            'nome' => ['nullable', 'string', 'max:120'],
            'modalidade' => ['required', Rule::in(self::MODALIDADES)],
            'duracao_min' => ['required', 'integer', 'min:1', 'max:600'],
            'distancia_km' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'calorias' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'fc_media' => ['nullable', 'integer', 'min:30', 'max:230'],
            'fc_maxima' => ['nullable', 'integer', 'min:30', 'max:230'],
            'intensidade' => ['nullable', 'string', 'max:30'],
            'treino_id' => [
                'nullable',
                'integer',
                Rule::exists('saude_treinos', 'id')->where('user_id', $userId),
            ],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'data' => $data['data'],
            'horario' => $data['horario'] ?? null,
            'nome' => $data['nome'] ?? null,
            'modalidade' => $data['modalidade'],
            'duracao_min' => $data['duracao_min'],
            'distancia_km' => $data['distancia_km'] ?? null,
            'calorias' => $data['calorias'] ?? null,
            'fc_media' => $data['fc_media'] ?? null,
            'fc_maxima' => $data['fc_maxima'] ?? null,
            'intensidade' => $data['intensidade'] ?? null,
            'treino_id' => $data['treino_id'] ?? null,
            'observacao' => $data['observacao'] ?? null,
        ];
    }

    private function authorizeOwnership(Request $request, SaudeCardioSessao $cardio): void
    {
        abort_unless((int) $cardio->user_id === (int) $request->user()->id, 403);
    }
}
