<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestmentTagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tags = InvestmentTag::query()
            ->where('user_id', $request->user()->id)
            ->withCount('investments')
            ->orderBy('name')
            ->get();

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $tag = InvestmentTag::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#64748b',
        ]);

        return response()->json($tag, 201);
    }

    public function update(Request $request, InvestmentTag $investmentTag): JsonResponse
    {
        $this->authorizeOwnership($request, $investmentTag);

        $data = $this->validateData($request, $investmentTag->id);

        $investmentTag->update([
            'name' => $data['name'],
            'color' => $data['color'] ?? $investmentTag->color,
        ]);

        return response()->json($investmentTag->fresh());
    }

    public function destroy(Request $request, InvestmentTag $investmentTag): JsonResponse
    {
        $this->authorizeOwnership($request, $investmentTag);

        $investmentTag->delete();

        return response()->json(['message' => 'Proposito removido com sucesso.']);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('investment_tags', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignoreId),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
    }

    private function authorizeOwnership(Request $request, InvestmentTag $tag): void
    {
        abort_unless((int) $tag->user_id === (int) $request->user()->id, 403);
    }
}
