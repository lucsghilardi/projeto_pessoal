<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreditCardInvoiceController extends Controller
{
    /**
     * Com ?month=YYYY-MM retorna a fatura do mês (lançamentos + pagamentos + totais).
     * Se a fatura ainda não existe, devolve uma "virtual" (sem id) com as datas calculadas.
     * Sem month, lista as faturas existentes do cartão.
     */
    public function index(Request $request, CreditCard $creditCard): JsonResponse
    {
        $this->authorizeCard($request, $creditCard);

        $month = $request->query('month');

        if (! $month) {
            $invoices = $creditCard->invoices()
                ->with(['transactions', 'payments'])
                ->orderByDesc('reference_month')
                ->get();

            return response()->json($invoices);
        }

        $invoice = $creditCard->invoices()
            ->where('reference_month', $month)
            ->with([
                'transactions' => fn ($q) => $q->orderBy('purchase_date')->orderBy('id'),
                'transactions.category:id,name,color,kind',
                'payments' => fn ($q) => $q->orderBy('paid_at'),
                'payments.bankAccount:id,name',
            ])
            ->first();

        if (! $invoice) {
            $window = $creditCard->windowForReferenceMonth($month);

            return response()->json([
                'id' => null,
                'credit_card_id' => $creditCard->id,
                'reference_month' => $month,
                'closing_date' => $window['closing_date'],
                'due_date' => $window['due_date'],
                'transactions' => [],
                'payments' => [],
                'total' => 0,
                'paid_total' => 0,
                'remaining' => 0,
                'status' => 'aberta',
            ]);
        }

        return response()->json($invoice);
    }

    /**
     * Registra um pagamento (parcial ou total) da fatura, debitando o saldo da conta escolhida.
     */
    public function pay(Request $request, CreditCardInvoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $request->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $invoice->load(['transactions', 'payments']);
        $remaining = $invoice->remaining;

        if ($remaining <= 0) {
            return response()->json(['message' => 'Esta fatura já está paga.'], 422);
        }

        if ($data['amount'] > $remaining + 0.001) {
            return response()->json(['message' => 'O valor é maior que o restante da fatura.'], 422);
        }

        DB::transaction(function () use ($request, $invoice, $data) {
            BankAccount::whereKey($data['bank_account_id'])->decrement('balance', $data['amount']);

            $invoice->payments()->create([
                'user_id' => $request->user()->id,
                'bank_account_id' => $data['bank_account_id'],
                'amount' => $data['amount'],
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
            ]);
        });

        return response()->json($this->loadFull($invoice->fresh()));
    }

    /**
     * Estorna um pagamento da fatura: devolve o valor ao saldo da conta.
     */
    public function unpay(Request $request, CreditCardInvoice $invoice, CreditCardInvoicePayment $payment): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        abort_unless((int) $payment->credit_card_invoice_id === (int) $invoice->id, 404);

        DB::transaction(function () use ($payment) {
            if ($payment->bank_account_id) {
                BankAccount::whereKey($payment->bank_account_id)->increment('balance', $payment->amount);
            }

            $payment->delete();
        });

        return response()->json($this->loadFull($invoice->fresh()));
    }

    private function loadFull(CreditCardInvoice $invoice): CreditCardInvoice
    {
        return $invoice->load([
            'transactions' => fn ($q) => $q->orderBy('purchase_date')->orderBy('id'),
            'transactions.category:id,name,color,kind',
            'payments' => fn ($q) => $q->orderBy('paid_at'),
            'payments.bankAccount:id,name',
        ]);
    }

    private function authorizeCard(Request $request, CreditCard $card): void
    {
        abort_unless((int) $card->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeInvoice(Request $request, CreditCardInvoice $invoice): void
    {
        abort_unless((int) $invoice->user_id === (int) $request->user()->id, 403);
    }
}
