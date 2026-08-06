<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        // Envelope para evitar o corpo "{}" que o JsonResponse gera com null.
        return response()->json([
            'meta' => SaudeMeta::where('user_id', $request->user()->id)->first(),
        ]);
    }

    /** Cria ou atualiza a meta (uma por usuário). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'peso_meta_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'data_alvo' => ['nullable', 'date_format:Y-m-d'],
            'altura_cm' => ['nullable', 'integer', 'min:100', 'max:250'],
        ]);

        $meta = SaudeMeta::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'peso_meta_kg' => $data['peso_meta_kg'] ?? null,
                'data_alvo' => $data['data_alvo'] ?? null,
                'altura_cm' => $data['altura_cm'] ?? null,
            ],
        );

        return response()->json(['meta' => $meta]);
    }
}
