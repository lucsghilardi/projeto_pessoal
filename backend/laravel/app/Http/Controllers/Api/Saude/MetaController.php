<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeMeta;
use App\Services\Saude\SaudeNutricaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            // Perfil nutricional (TMB/TDEE) e overrides das metas calculadas.
            'sexo' => ['nullable', Rule::in(['M', 'F'])],
            'data_nascimento' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'nivel_atividade' => ['nullable', Rule::in(array_keys(SaudeNutricaoService::FATORES_ATIVIDADE))],
            'calorias_alvo' => ['nullable', 'integer', 'min:800', 'max:6000'],
            'proteinas_alvo_g' => ['nullable', 'integer', 'min:20', 'max:400'],
        ]);

        $meta = SaudeMeta::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'peso_meta_kg' => $data['peso_meta_kg'] ?? null,
                'data_alvo' => $data['data_alvo'] ?? null,
                'altura_cm' => $data['altura_cm'] ?? null,
                'sexo' => $data['sexo'] ?? null,
                'data_nascimento' => $data['data_nascimento'] ?? null,
                'nivel_atividade' => $data['nivel_atividade'] ?? null,
                'calorias_alvo' => $data['calorias_alvo'] ?? null,
                'proteinas_alvo_g' => $data['proteinas_alvo_g'] ?? null,
            ],
        );

        return response()->json(['meta' => $meta]);
    }
}
