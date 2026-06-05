<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CreditCardInvoicePayment;
use App\Models\CreditCardTransaction;
use App\Models\Payable;
use App\Models\Receivable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinanceSummaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $month = $request->query('month');
        $base = $month ? Carbon::parse($month.'-01') : now();
        $start = $base->copy()->startOfMonth()->toDateString();
        $end = $base->copy()->endOfMonth()->toDateString();
        $ref = $base->format('Y-m');

        $accountsTotal = round(
            (float) BankAccount::query()->where('user_id', $userId)->sum('balance'),
            2,
        );

        $payables = Payable::query()
            ->with('category:id,name,color')
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$start, $end])
            ->get();

        $receivables = Receivable::query()
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$start, $end])
            ->get();

        // Gastos do cartão entram no DRE pelo mês da fatura (vencimento).
        $cardTransactions = CreditCardTransaction::query()
            ->with('category:id,name,color')
            ->where('user_id', $userId)
            ->whereHas('invoice', fn ($q) => $q->where('reference_month', $ref))
            ->get();

        $payTotal = round($payables->sum(fn ($p) => (float) $p->amount), 2);
        $payPaid = round($payables->where('is_paid', true)->sum(fn ($p) => (float) $p->amount), 2);

        $recTotal = round($receivables->sum(fn ($r) => (float) $r->amount), 2);
        $recReceived = round($receivables->where('is_received', true)->sum(fn ($r) => (float) $r->amount), 2);

        $cardTotal = round($cardTransactions->sum(fn ($t) => (float) $t->amount), 2);
        $cardPaid = round((float) CreditCardInvoicePayment::query()
            ->where('user_id', $userId)
            ->whereHas('invoice', fn ($q) => $q->where('reference_month', $ref))
            ->sum('amount'), 2);

        // by_category combina contas a pagar + gastos do cartão (para o DRE bater por categoria).
        $expenseItems = $payables
            ->map(fn ($p) => [
                'name' => $p->category->name ?? 'Sem categoria',
                'color' => $p->category->color ?? '#64748b',
                'amount' => (float) $p->amount,
            ])
            ->concat($cardTransactions->map(fn ($t) => [
                'name' => $t->category->name ?? 'Sem categoria',
                'color' => $t->category->color ?? '#64748b',
                'amount' => (float) $t->amount,
            ]));

        $byCategory = $expenseItems
            ->groupBy('name')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'color' => $group->first()['color'],
                'total' => round($group->sum('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'month' => $base->format('Y-m'),
            'accounts_total' => $accountsTotal,
            'payables' => [
                'total' => $payTotal,
                'paid' => $payPaid,
                'pending' => round($payTotal - $payPaid, 2),
            ],
            'receivables' => [
                'total' => $recTotal,
                'received' => $recReceived,
                'pending' => round($recTotal - $recReceived, 2),
            ],
            'credit_cards' => [
                'total' => $cardTotal,
                'paid' => $cardPaid,
                'pending' => round($cardTotal - $cardPaid, 2),
            ],
            'balance_month' => round($recTotal - $payTotal - $cardTotal, 2),
            'by_category' => $byCategory,
        ]);
    }
}
