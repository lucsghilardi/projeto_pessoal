<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Services\Saude\GarminImportService;
use App\Services\Saude\GarminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
