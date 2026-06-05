<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreditCardTransactionController extends Controller
{
    /**
     * Sugere a fatura (mês de referência / corte / vencimento) para a data de uma compra.
     */
    public function resolveInvoice(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->authorizeCard($request, $creditCard);

        $data = $request->validate([
            'date' => ['required', 'date'],
        ]);

        return response()->json($creditCard->resolveInvoiceWindow(Carbon::parse($data['date'])));
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'credit_card_id' => [
                'required',
                'integer',
                Rule::exists('credit_cards', 'id')->where('user_id', $userId),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $userId),
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'purchase_date' => ['required', 'date'],
            'installments_total' => ['nullable', 'integer', 'min:2', 'max:360'],
            'reference_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $card = CreditCard::query()->where('user_id', $userId)->findOrFail($data['credit_card_id']);
        $purchase = Carbon::parse($data['purchase_date'])->startOfDay();
        $total = (int) ($data['installments_total'] ?? 1);

        $firstRef = $data['reference_month']
            ?? $card->resolveInvoiceWindow($purchase)['reference_month'];

        $base = [
            'user_id' => $userId,
            'credit_card_id' => $card->id,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'],
            'amount' => $data['amount'],
            'purchase_date' => $purchase->toDateString(),
        ];

        $count = 0;

        DB::transaction(function () use ($card, $base, $total, $firstRef, &$count) {
            if ($total >= 2) {
                $group = (string) Str::uuid();
                for ($i = 0; $i < $total; $i++) {
                    $invoice = $card->invoiceForReferenceMonth($this->shiftReference($firstRef, $i));
                    CreditCardTransaction::create([
                        ...$base,
                        'credit_card_invoice_id' => $invoice->id,
                        'installment_number' => $i + 1,
                        'installments_total' => $total,
                        'group_id' => $group,
                    ]);
                    $count++;
                }
            } else {
                $invoice = $card->invoiceForReferenceMonth($firstRef);
                CreditCardTransaction::create([
                    ...$base,
                    'credit_card_invoice_id' => $invoice->id,
                ]);
                $count = 1;
            }
        });

        return response()->json(['message' => 'Lançamento(s) criado(s).', 'created' => $count], 201);
    }

    public function update(Request $request, CreditCardTransaction $creditCardTransaction): JsonResponse
    {
        $this->authorizeOwnership($request, $creditCardTransaction);

        $userId = $request->user()->id;

        $data = $request->validate([
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $userId),
            ],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $updates = [
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'amount' => $data['amount'],
        ];

        // Permite mover o lançamento para outra fatura.
        if (! empty($data['reference_month'])) {
            $invoice = $creditCardTransaction->card->invoiceForReferenceMonth($data['reference_month']);
            $updates['credit_card_invoice_id'] = $invoice->id;
        }

        $creditCardTransaction->update($updates);

        return response()->json(
            $creditCardTransaction->fresh()->load(['category:id,name,color,kind', 'invoice:id,reference_month,due_date'])
        );
    }

    public function destroy(Request $request, CreditCardTransaction $creditCardTransaction): JsonResponse
    {
        $this->authorizeOwnership($request, $creditCardTransaction);

        $userId = $request->user()->id;

        if ($request->query('scope') === 'group' && $creditCardTransaction->group_id) {
            CreditCardTransaction::query()
                ->where('user_id', $userId)
                ->where('group_id', $creditCardTransaction->group_id)
                ->delete();

            return response()->json(['message' => 'Lançamentos do grupo removidos.']);
        }

        $creditCardTransaction->delete();

        return response()->json(['message' => 'Lançamento removido.']);
    }

    private function shiftReference(string $reference, int $months): string
    {
        [$year, $month] = array_map('intval', explode('-', $reference));

        return Carbon::create($year, $month, 1)->addMonthsNoOverflow($months)->format('Y-m');
    }

    private function authorizeCard(Request $request, CreditCard $card): void
    {
        abort_unless((int) $card->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeOwnership(Request $request, CreditCardTransaction $transaction): void
    {
        abort_unless((int) $transaction->user_id === (int) $request->user()->id, 403);
    }
}
