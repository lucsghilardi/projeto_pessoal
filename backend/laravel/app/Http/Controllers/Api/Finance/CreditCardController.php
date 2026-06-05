<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\CreditCard;
use App\Models\CreditCardInvoicePayment;
use App\Models\CreditCardTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cards = CreditCard::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get()
            ->map(function (CreditCard $card) {
                $charged = (float) CreditCardTransaction::query()
                    ->where('credit_card_id', $card->id)
                    ->sum('amount');

                $paid = (float) CreditCardInvoicePayment::query()
                    ->whereHas('invoice', fn ($q) => $q->where('credit_card_id', $card->id))
                    ->sum('amount');

                $outstanding = round($charged - $paid, 2);
                $available = $card->limit !== null
                    ? round((float) $card->limit - $outstanding, 2)
                    : null;

                return array_merge($card->toArray(), [
                    'outstanding' => $outstanding,
                    'available_limit' => $available,
                ]);
            });

        return response()->json(['credit_cards' => $cards]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $card = CreditCard::create([
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return response()->json($card, 201);
    }

    public function update(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->authorizeOwnership($request, $creditCard);

        $data = $this->validateData($request, $creditCard);

        $creditCard->update($data);

        return response()->json($creditCard->fresh());
    }

    public function destroy(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->authorizeOwnership($request, $creditCard);

        $creditCard->delete();

        return response()->json(['message' => 'Cartão removido com sucesso.']);
    }

    private function validateData(Request $request, ?CreditCard $ignore = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('credit_cards', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'brand' => ['nullable', 'string', 'max:255'],
            'last_four' => ['nullable', 'string', 'digits:4'],
            'limit' => ['nullable', 'numeric', 'min:0'],
            'closing_day' => ['required', 'integer', 'min:1', 'max:31'],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeOwnership(Request $request, CreditCard $card): void
    {
        abort_unless((int) $card->user_id === (int) $request->user()->id, 403);
    }
}
