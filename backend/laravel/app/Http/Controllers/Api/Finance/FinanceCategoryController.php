<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceCategoryController extends Controller
{
    public const KINDS = ['despesa', 'receita'];

    public function index(Request $request): JsonResponse
    {
        $categories = FinanceCategory::query()
            ->where('user_id', $request->user()->id)
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        return response()->json($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $category = FinanceCategory::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'kind' => $data['kind'],
            'color' => $data['color'] ?? '#64748b',
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, FinanceCategory $financeCategory): JsonResponse
    {
        $this->authorizeOwnership($request, $financeCategory);

        $data = $this->validateData($request, $financeCategory);

        $financeCategory->update([
            'name' => $data['name'],
            'kind' => $data['kind'],
            'color' => $data['color'] ?? $financeCategory->color,
        ]);

        return response()->json($financeCategory->fresh());
    }

    public function destroy(Request $request, FinanceCategory $financeCategory): JsonResponse
    {
        $this->authorizeOwnership($request, $financeCategory);

        $financeCategory->delete();

        return response()->json(['message' => 'Categoria removida com sucesso.']);
    }

    private function validateData(Request $request, ?FinanceCategory $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('finance_categories', 'name')
                    ->where('user_id', $request->user()->id)
                    ->where('kind', $request->input('kind'))
                    ->ignore($ignore?->id),
            ],
            'kind' => ['required', Rule::in(self::KINDS)],
            'color' => ['nullable', 'string', 'max:20'],
        ]);
    }

    private function authorizeOwnership(Request $request, FinanceCategory $category): void
    {
        abort_unless((int) $category->user_id === (int) $request->user()->id, 403);
    }
}
