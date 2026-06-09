<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\Acerto;
use App\Models\AcertoSettlement;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcertoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Acerto::query()
            ->with(['category:id,name,color,kind', 'settlements.bankAccount:id,name'])
            ->where('user_id', $request->user()->id)
            ->orderBy('is_settled')
            ->orderByDesc('created_at');

        if (in_array($request->query('direction'), ['pagar', 'receber'], true)) {
            $query->where('direction', $request->query('direction'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $acerto = Acerto::create([
            'user_id' => $request->user()->id,
            'category_id' => $data['category_id'] ?? null,
            'direction' => $data['direction'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
            'is_settled' => false,
        ]);

        return response()->json($this->loadFull($acerto), 201);
    }

    public function update(Request $request, Acerto $acerto): JsonResponse
    {
        $this->authorizeOwnership($request, $acerto);

        $data = $this->validateData($request, ignoreDirection: true);

        // O valor total não pode ficar menor do que o já baixado.
        if ((float) $data['amount'] < $acerto->settled_amount - 0.001) {
            return response()->json([
                'message' => 'O valor total não pode ser menor do que o já baixado.',
            ], 422);
        }

        $acerto->update([
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);

        $this->refreshSettledFlag($acerto);

        return response()->json($this->loadFull($acerto->fresh()));
    }

    public function destroy(Request $request, Acerto $acerto): JsonResponse
    {
        $this->authorizeOwnership($request, $acerto);

        DB::transaction(function () use ($acerto) {
            // Estorna o saldo de todas as baixas antes de remover.
            foreach ($acerto->settlements as $settlement) {
                $this->reverseBalance($acerto, $settlement);
            }

            $acerto->delete(); // cascade remove as settlements.
        });

        return response()->json(['message' => 'Acerto removido.']);
    }

    /**
     * Registra uma baixa (parcial ou total) e ajusta o saldo da conta escolhida.
     * receber -> credita a conta; pagar -> debita a conta.
     */
    public function settle(Request $request, Acerto $acerto): JsonResponse
    {
        $this->authorizeOwnership($request, $acerto);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $request->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'settled_at' => ['nullable', 'date'],
        ]);

        $remaining = $acerto->remaining;

        if ($remaining <= 0) {
            return response()->json(['message' => 'Este acerto já está quitado.'], 422);
        }

        if ($data['amount'] > $remaining + 0.001) {
            return response()->json(['message' => 'O valor é maior que o restante do acerto.'], 422);
        }

        DB::transaction(function () use ($request, $acerto, $data) {
            if ($acerto->direction === 'receber') {
                BankAccount::whereKey($data['bank_account_id'])->increment('balance', $data['amount']);
            } else {
                BankAccount::whereKey($data['bank_account_id'])->decrement('balance', $data['amount']);
            }

            $acerto->settlements()->create([
                'user_id' => $request->user()->id,
                'bank_account_id' => $data['bank_account_id'],
                'amount' => $data['amount'],
                'settled_at' => $data['settled_at'] ?? now()->toDateString(),
            ]);

            $this->refreshSettledFlag($acerto);
        });

        return response()->json($this->loadFull($acerto->fresh()));
    }

    /**
     * Estorna uma baixa: desfaz o ajuste de saldo da conta e remove a baixa.
     */
    public function unsettle(Request $request, Acerto $acerto, AcertoSettlement $settlement): JsonResponse
    {
        $this->authorizeOwnership($request, $acerto);
        abort_unless((int) $settlement->acerto_id === (int) $acerto->id, 404);

        DB::transaction(function () use ($acerto, $settlement) {
            $this->reverseBalance($acerto, $settlement);
            $settlement->delete();
            $this->refreshSettledFlag($acerto);
        });

        return response()->json($this->loadFull($acerto->fresh()));
    }

    /**
     * Reverte no saldo da conta o efeito de uma baixa (oposto de settle).
     */
    private function reverseBalance(Acerto $acerto, AcertoSettlement $settlement): void
    {
        if (! $settlement->bank_account_id) {
            return;
        }

        if ($acerto->direction === 'receber') {
            BankAccount::whereKey($settlement->bank_account_id)->decrement('balance', $settlement->amount);
        } else {
            BankAccount::whereKey($settlement->bank_account_id)->increment('balance', $settlement->amount);
        }
    }

    private function refreshSettledFlag(Acerto $acerto): void
    {
        $acerto->update(['is_settled' => $acerto->fresh()->remaining <= 0.001]);
    }

    private function validateData(Request $request, bool $ignoreDirection = false): array
    {
        $rules = [
            'description' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $request->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if (! $ignoreDirection) {
            $rules['direction'] = ['required', Rule::in(['pagar', 'receber'])];
        }

        return $request->validate($rules);
    }

    private function loadFull(Acerto $acerto): Acerto
    {
        return $acerto->load(['category:id,name,color,kind', 'settlements.bankAccount:id,name']);
    }

    private function authorizeOwnership(Request $request, Acerto $acerto): void
    {
        abort_unless((int) $acerto->user_id === (int) $request->user()->id, 403);
    }
}
