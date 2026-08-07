<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Services\Saude\GarminImportService;
use App\Services\Saude\GarminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GarminController extends Controller
{
    public function status(GarminService $garmin): JsonResponse
    {
        return response()->json([
            'configurado' => $garmin->configOk(),
            'status' => $garmin->status(),
        ]);
    }

    /**
     * Sincroniza na hora e devolve as contagens. Roda inline (e não na fila)
     * de propósito: num painel pessoal, ver o resultado vale mais do que
     * devolver 202 e deixar o usuário adivinhando.
     */
    public function sincronizar(Request $request, GarminService $garmin, GarminImportService $import): JsonResponse
    {
        abort_unless($garmin->configOk(), 422, 'A integração com o Garmin não está configurada.');

        $data = $request->validate([
            'dias' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        try {
            $resultado = $import->sincronizar($data['dias'] ?? null);
        } catch (RuntimeException $erro) {
            return response()->json(['message' => $erro->getMessage()], 422);
        }

        return response()->json($resultado);
    }

    /**
     * Versão silenciosa, chamada pelo frontend ao abrir as telas de Saúde.
     * Nunca responde erro de integração (auto-sync não pode virar toast de
     * erro na navegação) e usa uma trava de 10 min por usuário para não
     * martelar o Garmin — a API é não oficial e aplica rate limit.
     */
    public function sincronizarAuto(Request $request, GarminService $garmin, GarminImportService $import): JsonResponse
    {
        if (! $garmin->configOk()) {
            return response()->json(['executado' => false, 'motivo' => 'nao_configurado']);
        }

        // Cache::add é atômico: duas abas abertas juntas disparam um sync só.
        // A trava entra ANTES de rodar — se falhar, o job horário cobre.
        $chave = 'saude:garmin:auto:'.$request->user()->id;
        if (! Cache::add($chave, now()->toIso8601String(), now()->addMinutes(10))) {
            return response()->json(['executado' => false, 'motivo' => 'recente']);
        }

        try {
            $resultado = $import->sincronizar();
        } catch (RuntimeException $erro) {
            Log::warning('Garmin: auto-sync falhou.', ['erro' => $erro->getMessage()]);

            return response()->json(['executado' => false, 'motivo' => 'erro']);
        }

        return response()->json(['executado' => true, ...$resultado]);
    }
}
