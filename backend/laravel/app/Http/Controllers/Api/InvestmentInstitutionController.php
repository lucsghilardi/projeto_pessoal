<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentInstitution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestmentInstitutionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $institutions = InvestmentInstitution::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json($institutions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $institution = InvestmentInstitution::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
        ]);

        return response()->json($institution, 201);
    }

    public function update(Request $request, InvestmentInstitution $investmentInstitution): JsonResponse
    {
        $this->authorizeOwnership($request, $investmentInstitution);

        $data = $this->validateData($request, $investmentInstitution->id);

        $oldName = $investmentInstitution->name;
        $investmentInstitution->update(['name' => $data['name']]);

        // Mantem os investimentos sincronizados (institution e salvo como texto).
        if ($oldName !== $data['name']) {
            Investment::query()
                ->where('user_id', $request->user()->id)
                ->where('institution', $oldName)
                ->update(['institution' => $data['name']]);
        }

        return response()->json($investmentInstitution->fresh());
    }

    public function destroy(Request $request, InvestmentInstitution $investmentInstitution): JsonResponse
    {
        $this->authorizeOwnership($request, $investmentInstitution);

        $investmentInstitution->delete();

        return response()->json(['message' => 'Instituicao removida com sucesso.']);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('investment_institutions', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignoreId),
            ],
        ]);
    }

    private function authorizeOwnership(Request $request, InvestmentInstitution $institution): void
    {
        abort_unless((int) $institution->user_id === (int) $request->user()->id, 403);
    }
}
