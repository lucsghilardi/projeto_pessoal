<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeCardioSessao;
use App\Services\Saude\GarminImportService;
use App\Services\Saude\GarminService;
use App\Services\Saude\SaudeCardioAnaliseService;
use App\Services\Saude\SaudeCardioService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

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

    /**
     * A corrida inteira: sessão, detalhe do relógio, comparativo, evolução e a
     * análise da IA se já existir. Só lê — não fala com o Garmin.
     */
    public function show(
        Request $request,
        SaudeCardioSessao $cardio,
        SaudeCardioAnaliseService $analise,
    ): JsonResponse {
        $this->authorizeOwnership($request, $cardio);

        $cardio->load(['treino:id,nome', 'detalhe', 'analise']);
        $detalhe = $cardio->detalhe;

        return response()->json([
            'sessao' => $cardio->only([
                'id', 'treino_id', 'data', 'horario', 'nome', 'modalidade', 'duracao_min',
                'distancia_km', 'calorias', 'fc_media', 'fc_maxima', 'intensidade',
                'origem', 'garmin_activity_id', 'observacao',
            ]) + ['treino' => $cardio->treino],
            'detalhe' => $detalhe,
            'metricas' => $analise->metricas($cardio, $detalhe),
            'splits' => $analise->analiseSplits($detalhe),
            'benchmarks' => $analise->benchmarks($request->user(), $cardio, $detalhe),
            'evolucao' => $analise->evolucao($request->user(), $cardio),
            'analise' => $cardio->analise,
        ]);
    }

    /**
     * Busca os splits e as zonas de FC no Garmin, sob demanda.
     *
     * São três requisições ao Garmin, então o front chama isto uma vez, quando
     * abre uma corrida que ainda não tem detalhe. O lock evita que duas abas
     * (ou um duplo clique) disparem a mesma busca em paralelo.
     */
    public function detalhe(
        Request $request,
        SaudeCardioSessao $cardio,
        GarminImportService $import,
        GarminService $garmin,
    ): JsonResponse {
        $this->authorizeOwnership($request, $cardio);

        // Só o dono da conta Garmin fala com o sidecar. Hoje ninguém mais chega
        // aqui (garmin_activity_id só é gravado pelo import), mas isso depende
        // de três arquivos concordarem — melhor barrar por construção.
        abort_unless(
            $garmin->ehDono($request->user()),
            403,
            'A integração com o Garmin é exclusiva da conta configurada em GARMIN_USER_EMAIL.',
        );

        if ($cardio->garmin_activity_id === null) {
            return response()->json([
                'message' => 'Esta sessão foi lançada à mão: não há detalhe no Garmin para buscar.',
            ], 422);
        }

        $chave = "saude:cardio:detalhe:{$cardio->id}";

        if (! Cache::add($chave, now()->toIso8601String(), now()->addMinutes(5))) {
            return response()->json(['message' => 'Já existe uma busca em andamento para esta corrida.'], 409);
        }

        try {
            $detalhe = $import->importarDetalhe($cardio);
        } catch (RuntimeException $erro) {
            Cache::forget($chave);

            return response()->json(['message' => $erro->getMessage()], 422);
        }

        Cache::forget($chave);

        return response()->json($detalhe);
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
