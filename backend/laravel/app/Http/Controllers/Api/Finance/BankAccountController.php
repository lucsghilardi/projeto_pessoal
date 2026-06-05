<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = BankAccount::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'accounts' => $accounts,
            'total' => round($accounts->sum(fn ($a) => (float) $a->balance), 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $account = BankAccount::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'balance' => $data['balance'] ?? 0,
        ]);

        return response()->json($account, 201);
    }

    public function update(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizeOwnership($request, $bankAccount);

        $data = $this->validateData($request, $bankAccount);

        $bankAccount->update([
            'name' => $data['name'],
            'balance' => $data['balance'] ?? 0,
        ]);

        return response()->json($bankAccount->fresh());
    }

    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        $this->authorizeOwnership($request, $bankAccount);

        $bankAccount->delete();

        return response()->json(['message' => 'Conta removida com sucesso.']);
    }

    private function validateData(Request $request, ?BankAccount $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('bank_accounts', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'balance' => ['nullable', 'numeric'],
        ]);
    }

    private function authorizeOwnership(Request $request, BankAccount $account): void
    {
        abort_unless((int) $account->user_id === (int) $request->user()->id, 403);
    }
}
