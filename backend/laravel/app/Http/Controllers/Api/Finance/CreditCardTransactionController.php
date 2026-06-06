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
            // Quando true, "amount" é o valor TOTAL da compra e é dividido entre as parcelas.
            // Quando false (padrão), "amount" já é o valor de cada parcela.
            'amount_is_total' => ['nullable', 'boolean'],
            'reference_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $card = CreditCard::query()->where('user_id', $userId)->findOrFail($data['credit_card_id']);
        $purchase = Carbon::parse($data['purchase_date'])->startOfDay();
        $total = (int) ($data['installments_total'] ?? 1);

        $firstRef = $data['reference_month']
            ?? $card->resolveInvoiceWindow($purchase)['reference_month'];

        $amounts = $this->splitInstallments(
            (float) $data['amount'],
            $total,
            (bool) ($data['amount_is_total'] ?? false),
        );

        $base = [
            'user_id' => $userId,
            'credit_card_id' => $card->id,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'],
            'purchase_date' => $purchase->toDateString(),
        ];

        $count = 0;

        DB::transaction(function () use ($card, $base, $total, $firstRef, $amounts, &$count) {
            if ($total >= 2) {
                $group = (string) Str::uuid();
                for ($i = 0; $i < $total; $i++) {
                    $invoice = $card->invoiceForReferenceMonth($this->shiftReference($firstRef, $i));
                    CreditCardTransaction::create([
                        ...$base,
                        'amount' => $amounts[$i],
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
                    'amount' => $amounts[0],
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
            'credit_card_id' => [
                'nullable',
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
            'reference_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $targetCardId = (int) ($data['credit_card_id'] ?? $creditCardTransaction->credit_card_id);
        $cardChanged = $targetCardId !== (int) $creditCardTransaction->credit_card_id;

        $updates = [
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'amount' => $data['amount'],
        ];

        DB::transaction(function () use ($userId, $creditCardTransaction, $data, $updates, $targetCardId, $cardChanged) {
            if ($cardChanged) {
                $newCard = CreditCard::query()->where('user_id', $userId)->findOrFail($targetCardId);

                // Lançamento parcelado: move o grupo inteiro para o novo cartão,
                // preservando o cronograma (cada parcela na fatura equivalente).
                if ($creditCardTransaction->group_id) {
                    $this->moveGroupToCard($creditCardTransaction, $newCard, $data['reference_month'] ?? null);
                    $updates['credit_card_id'] = $newCard->id;
                    $updates['credit_card_invoice_id'] = $creditCardTransaction->fresh()->credit_card_invoice_id;
                } else {
                    $ref = $data['reference_month'] ?? $creditCardTransaction->invoice->reference_month;
                    $invoice = $newCard->invoiceForReferenceMonth($ref);
                    $updates['credit_card_id'] = $newCard->id;
                    $updates['credit_card_invoice_id'] = $invoice->id;
                }
            } elseif (! empty($data['reference_month'])) {
                // Mesmo cartão, só muda a fatura.
                $invoice = $creditCardTransaction->card->invoiceForReferenceMonth($data['reference_month']);
                $updates['credit_card_invoice_id'] = $invoice->id;
            }

            $creditCardTransaction->update($updates);
        });

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

    /**
     * Move todas as parcelas de um grupo para outro cartão, mantendo o cronograma:
     * a parcela editada fica no mês de referência informado (ou no atual) e as demais
     * são recalculadas a partir dele, cada uma na fatura equivalente do novo cartão.
     */
    private function moveGroupToCard(CreditCardTransaction $edited, CreditCard $newCard, ?string $referenceMonth): void
    {
        $anchorRef = $referenceMonth ?? $edited->invoice->reference_month;
        $firstRef = $this->shiftReference($anchorRef, -((int) $edited->installment_number - 1));

        $group = CreditCardTransaction::query()
            ->where('user_id', $edited->user_id)
            ->where('group_id', $edited->group_id)
            ->get();

        foreach ($group as $parcela) {
            $ref = $this->shiftReference($firstRef, (int) $parcela->installment_number - 1);
            $invoice = $newCard->invoiceForReferenceMonth($ref);
            $parcela->update([
                'credit_card_id' => $newCard->id,
                'credit_card_invoice_id' => $invoice->id,
            ]);
        }
    }

    /**
     * Calcula o valor de cada parcela.
     *
     * - amount_is_total = false: cada parcela vale exatamente $amount.
     * - amount_is_total = true: divide $amount entre as parcelas; o centavo que
     *   sobra da divisão vai na 1ª parcela para a soma bater com o total.
     *
     * @return list<float>
     */
    private function splitInstallments(float $amount, int $total, bool $amountIsTotal): array
    {
        $count = max(1, $total);

        if ($count < 2 || ! $amountIsTotal) {
            return array_fill(0, $count, round($amount, 2));
        }

        $totalCents = (int) round($amount * 100);
        $baseCents = intdiv($totalCents, $count);
        $remainder = $totalCents - $baseCents * $count;

        $amounts = [];
        for ($i = 0; $i < $count; $i++) {
            $cents = $baseCents + ($i === 0 ? $remainder : 0);
            $amounts[] = $cents / 100;
        }

        return $amounts;
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
