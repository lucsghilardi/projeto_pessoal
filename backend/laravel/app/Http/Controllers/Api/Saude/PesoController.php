<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudePeso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PesoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $pesos = SaudePeso::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['de'] ?? null, fn ($q, $de) => $q->where('data', '>=', $de))
            ->when($filters['ate'] ?? null, fn ($q, $ate) => $q->where('data', '<=', $ate))
            ->orderBy('data')
            ->get();

        return response()->json($pesos);
    }

    /** Registra a pesagem do dia (upsert por data). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'date_format:Y-m-d'],
            'peso_kg' => ['required', 'numeric', 'min:20', 'max:400'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $peso = SaudePeso::updateOrCreate(
            ['user_id' => $request->user()->id, 'data' => $data['data']],
            ['peso_kg' => $data['peso_kg'], 'observacao' => $data['observacao'] ?? null],
        );

        return response()->json($peso, 201);
    }

    public function update(Request $request, SaudePeso $peso): JsonResponse
    {
        $this->authorizeOwnership($request, $peso);

        $data = $request->validate([
            'peso_kg' => ['required', 'numeric', 'min:20', 'max:400'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        $peso->update([
            'peso_kg' => $data['peso_kg'],
            'observacao' => $data['observacao'] ?? null,
        ]);

        return response()->json($peso->fresh());
    }

    public function destroy(Request $request, SaudePeso $peso): JsonResponse
    {
        $this->authorizeOwnership($request, $peso);

        $peso->delete();

        return response()->json(['message' => 'Pesagem removida com sucesso.']);
    }

    private function authorizeOwnership(Request $request, SaudePeso $peso): void
    {
        abort_unless((int) $peso->user_id === (int) $request->user()->id, 403);
    }
}
