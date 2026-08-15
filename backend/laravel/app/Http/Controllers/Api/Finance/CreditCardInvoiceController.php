<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\CreditCardTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
     * Previsão das faturas de um mês (YYYY-MM = mês do VENCIMENTO), usada na tela
     * de Contas a pagar: todo cartão ativo aparece, mesmo com fatura R$ 0,00.
     *
     * SOMENTE LEITURA por contrato: não usar invoiceForReferenceMonth(), que cria
     * a fatura no banco — listar contas a pagar não pode gravar nada.
     */
    public function forecast(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        $userId = $request->user()->id;
        $month = $request->query('month') ?: now()->format('Y-m');

        $cards = CreditCard::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $invoices = CreditCardInvoice::query()
            ->where('user_id', $userId)
            ->where('reference_month', $month)
            ->get()
            ->keyBy('credit_card_id');

        // Cartão desativado que ainda tem fatura no mês continua na lista:
        // desativar o cartão não apaga a dívida que já existe.
        $orphans = $invoices->keys()->diff($cards->keys());
        if ($orphans->isNotEmpty()) {
            $cards = $cards->union(
                CreditCard::query()->where('user_id', $userId)->whereIn('id', $orphans)->get()->keyBy('id')
            );
        }

        $invoiceIds = $invoices->pluck('id')->all();

        // Somas agregadas em 2 queries: os accessors total/paid_total do model
        // carregam as relações inteiras e dariam N+1 aqui.
        $totals = $invoiceIds ? CreditCardTransaction::query()
            ->whereIn('credit_card_invoice_id', $invoiceIds)
            ->groupBy('credit_card_invoice_id')
            ->selectRaw('credit_card_invoice_id, sum(amount) as total')
            ->pluck('total', 'credit_card_invoice_id') : collect();

        $paid = $invoiceIds ? CreditCardInvoicePayment::query()
            ->whereIn('credit_card_invoice_id', $invoiceIds)
            ->groupBy('credit_card_invoice_id')
            ->selectRaw('credit_card_invoice_id, sum(amount) as total')
            ->pluck('total', 'credit_card_invoice_id') : collect();

        // Ciclo fechado = hoje ESTRITAMENTE depois do corte. CreditCard::resolveInvoiceWindow
        // usa `if ($date->day > $closingDay)`, então uma compra feita NO dia do corte ainda
        // entra nesta fatura — ela só para de receber lançamentos no dia seguinte.
        // Comparação como string "Y-m-d" (lexicográfica = cronológica): comparar Carbon
        // com Carbon misturaria o fuso local com o UTC do banco e fecharia um dia antes.
        $today = Carbon::today(config('finance.timezone'))->toDateString();

        $rows = $cards
            ->map(function (CreditCard $card) use ($invoices, $totals, $paid, $month, $today) {
                $invoice = $invoices->get($card->id);

                // A fatura já gravada manda nas datas: foi o closing_date dela que decidiu
                // em qual fatura cada compra caiu. Recalcular pelo cartão faria uma troca de
                // closing_day/due_day reescrever o passado e divergir da tela do cartão.
                $window = $invoice
                    ? [
                        'closing_date' => $invoice->closing_date->toDateString(),
                        'due_date' => $invoice->due_date->toDateString(),
                    ]
                    : $card->windowForReferenceMonth($month);

                $total = $invoice ? round((float) ($totals[$invoice->id] ?? 0), 2) : 0.0;
                $paidTotal = $invoice ? round((float) ($paid[$invoice->id] ?? 0), 2) : 0.0;

                return [
                    'credit_card_id' => $card->id,
                    'invoice_id' => $invoice?->id,
                    'card_name' => $card->name,
                    'last_four' => $card->last_four,
                    'card_is_active' => (bool) $card->is_active,
                    'reference_month' => $month,
                    'closing_date' => $window['closing_date'],
                    'due_date' => $window['due_date'],
                    'is_closed' => $today > $window['closing_date'],
                    'total' => $total,
                    'paid_total' => $paidTotal,
                    'remaining' => round($total - $paidTotal, 2),
                    // Status de PAGAMENTO (mesma regra do accessor do model), não de ciclo.
                    'status' => $total > 0 && $paidTotal >= $total
                        ? 'paga'
                        : ($paidTotal > 0 ? 'parcial' : 'aberta'),
                ];
            })
            ->sortBy([['due_date', 'asc'], ['card_name', 'asc']])
            ->values();

        return response()->json($rows);
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
