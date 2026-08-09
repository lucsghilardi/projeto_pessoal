<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeSono;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SonoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $sonos = SaudeSono::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['de'] ?? null, fn ($q, $de) => $q->where('data', '>=', $de))
            ->when($filters['ate'] ?? null, fn ($q, $ate) => $q->where('data', '<=', $ate))
            ->orderBy('data')
            ->get();

        return response()->json($sonos);
    }

    /**
     * Lançamento manual da noite (upsert por data — uma noite por dia).
     * Grava `origem = manual`, o que blinda o registro contra o próximo import
     * do Garmin: correção feita à mão vence o relógio.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request, comData: true);
        $dia = $data['data'];
        unset($data['data']);

        $sono = SaudeSono::updateOrCreate(
            ['user_id' => $request->user()->id, 'data' => $dia],
            [...$data, 'origem' => 'manual'],
        );

        return response()->json($sono, 201);
    }

    /** Edição livre — inclusive para corrigir a noite que veio do relógio. */
    public function update(Request $request, SaudeSono $sono): JsonResponse
    {
        $this->authorizeOwnership($request, $sono);

        // Editar a noite importada a torna manual: a partir daí ela é a versão
        // do usuário, e o import passa a respeitá-la.
        $sono->update([...$this->validateData($request), 'origem' => 'manual']);

        return response()->json($sono->fresh());
    }

    public function destroy(Request $request, SaudeSono $sono): JsonResponse
    {
        $this->authorizeOwnership($request, $sono);

        $sono->delete();

        return response()->json(['message' => 'Noite removida com sucesso.']);
    }

    /**
     * Só devolve os campos que vieram na requisição — assim editar a duração de
     * uma noite importada não zera as fases e o score que o relógio mediu (o
     * formulário não os edita, e o que ele não manda tem que ficar como está).
     * Mandar `null` explícito continua limpando o campo.
     *
     * `comData` inclui a data: no update ela já está no registro e mudá-la
     * esbarraria no unique(user_id, data).
     *
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $comData = false): array
    {
        $regras = [
            'duracao_min' => [$comData ? 'required' : 'sometimes', 'integer', 'min:1', 'max:1440'],
            'profundo_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'leve_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'rem_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'acordado_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'cochilo_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'despertares' => ['nullable', 'integer', 'min:0', 'max:255'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'inicio' => ['nullable', 'date_format:H:i'],
            'fim' => ['nullable', 'date_format:H:i'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ];

        if ($comData) {
            $regras = ['data' => ['required', 'date_format:Y-m-d']] + $regras;
        }

        return $request->validate($regras);
    }

    private function authorizeOwnership(Request $request, SaudeSono $sono): void
    {
        abort_unless((int) $sono->user_id === (int) $request->user()->id, 403);
    }
}
