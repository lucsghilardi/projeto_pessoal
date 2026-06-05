<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assets = Asset::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('value')
            ->orderBy('name')
            ->get();

        return response()->json([
            'assets' => $assets,
            'total' => round($assets->sum(fn ($a) => (float) $a->value), 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $asset = Asset::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'value' => $data['value'] ?? 0,
        ]);

        return response()->json($asset, 201);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeOwnership($request, $asset);

        $data = $this->validateData($request, $asset);

        $asset->update([
            'name' => $data['name'],
            'value' => $data['value'] ?? 0,
        ]);

        return response()->json($asset->fresh());
    }

    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeOwnership($request, $asset);

        $asset->delete();

        return response()->json(['message' => 'Patrimônio removido com sucesso.']);
    }

    private function validateData(Request $request, ?Asset $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'value' => ['nullable', 'numeric'],
        ]);
    }

    private function authorizeOwnership(Request $request, Asset $asset): void
    {
        abort_unless((int) $asset->user_id === (int) $request->user()->id, 403);
    }
}
