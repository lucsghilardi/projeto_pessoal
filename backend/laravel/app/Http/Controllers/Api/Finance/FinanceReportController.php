<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\AcertoSettlement;
use App\Models\CreditCardTransaction;
use App\Models\Payable;
use App\Models\Receivable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceReportController extends Controller
{
    private const DEFAULT_MONTHS = 12;

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Janela de meses: ?end=YYYY-MM&start=YYYY-MM (default: últimos 12 meses até o atual).
        $end = $request->query('end')
            ? Carbon::parse($request->query('end').'-01')->startOfMonth()
            : now()->startOfMonth();

        $start = $request->query('start')
            ? Carbon::parse($request->query('start').'-01')->startOfMonth()
            : $end->copy()->subMonths(self::DEFAULT_MONTHS - 1);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        // Lista de meses (YYYY-MM) cobertos pelo relatório.
        $months = [];
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addMonth()) {
            $months[] = $cursor->format('Y-m');
        }

        $rangeStart = $start->copy()->startOfMonth()->toDateString();
        $rangeEnd = $end->copy()->endOfMonth()->toDateString();

        $payables = Payable::query()
            ->with('category:id,name,color')
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$rangeStart, $rangeEnd])
            ->get();

        $receivables = Receivable::query()
            ->with('category:id,name,color')
            ->where('user_id', $userId)
            ->whereBetween('due_date', [$rangeStart, $rangeEnd])
            ->get();

        // Gastos do cartão entram pelo mês da fatura (vencimento), igual ao DRE.
        $cardTransactions = CreditCardTransaction::query()
            ->with(['category:id,name,color', 'invoice:id,reference_month'])
            ->where('user_id', $userId)
            ->whereHas('invoice', fn ($q) => $q->whereIn('reference_month', $months))
            ->get();

        // Acertos (a pagar/receber sem prazo) entram pelo mês de cada baixa.
        $acertoSettlements = AcertoSettlement::query()
            ->with('acerto:id,direction,category_id', 'acerto.category:id,name,color')
            ->where('user_id', $userId)
            ->whereBetween('settled_at', [$rangeStart, $rangeEnd])
            ->get();

        $acertoPayItems = $acertoSettlements
            ->filter(fn ($s) => $s->acerto && $s->acerto->direction === 'pagar')
            ->map(fn ($s) => [
                'month' => Carbon::parse($s->settled_at)->format('Y-m'),
                'name' => $s->acerto->category->name ?? 'Sem categoria',
                'color' => $s->acerto->category->color ?? '#64748b',
                'amount' => (float) $s->amount,
            ]);

        $acertoReceiveItems = $acertoSettlements
            ->filter(fn ($s) => $s->acerto && $s->acerto->direction === 'receber')
            ->map(fn ($s) => [
                'month' => Carbon::parse($s->settled_at)->format('Y-m'),
                'name' => $s->acerto->category->name ?? 'Sem categoria',
                'color' => $s->acerto->category->color ?? '#22c55e',
                'amount' => (float) $s->amount,
            ]);

        // Despesas e receitas normalizadas em {month, name, color, amount}.
        $expenseItems = $payables
            ->map(fn ($p) => [
                'month' => Carbon::parse($p->due_date)->format('Y-m'),
                'name' => $p->category->name ?? 'Sem categoria',
                'color' => $p->category->color ?? '#64748b',
                'amount' => (float) $p->amount,
            ])
            ->concat($cardTransactions->map(fn ($t) => [
                'month' => $t->invoice->reference_month,
                'name' => $t->category->name ?? 'Sem categoria',
                'color' => $t->category->color ?? '#64748b',
                'amount' => (float) $t->amount,
            ]))
            ->concat($acertoPayItems);

        $incomeItems = $receivables
            ->map(fn ($r) => [
                'month' => Carbon::parse($r->due_date)->format('Y-m'),
                'name' => $r->category->name ?? 'Sem categoria',
                'color' => $r->category->color ?? '#22c55e',
                'amount' => (float) $r->amount,
            ])
            ->concat($acertoReceiveItems);

        return response()->json([
            'start' => $start->format('Y-m'),
            'end' => $end->format('Y-m'),
            'months' => $months,
            'monthly' => $this->monthlySeries($months, $expenseItems, $incomeItems),
            'expense_by_category' => $this->byCategoryMonthly($months, $expenseItems),
            'cashflow_by_category' => $this->cashflowByCategory($expenseItems, $incomeItems),
        ]);
    }

    /**
     * Série mês a mês: receita x despesa x saldo.
     */
    private function monthlySeries(array $months, Collection $expenses, Collection $income): array
    {
        $expenseByMonth = $expenses->groupBy('month')->map(fn ($g) => $g->sum('amount'));
        $incomeByMonth = $income->groupBy('month')->map(fn ($g) => $g->sum('amount'));

        return collect($months)->map(function (string $month) use ($expenseByMonth, $incomeByMonth) {
            $expense = round((float) ($expenseByMonth[$month] ?? 0), 2);
            $incomeValue = round((float) ($incomeByMonth[$month] ?? 0), 2);

            return [
                'month' => $month,
                'income' => $incomeValue,
                'expense' => $expense,
                'balance' => round($incomeValue - $expense, 2),
            ];
        })->all();
    }

    /**
     * Despesas por categoria, em fluxo de caixa mês a mês (matriz categoria x mês).
     */
    private function byCategoryMonthly(array $months, Collection $expenses): array
    {
        return $expenses
            ->groupBy('name')
            ->map(function (Collection $group, string $name) use ($months) {
                $byMonth = $group->groupBy('month')->map(fn ($g) => round((float) $g->sum('amount'), 2));

                $monthly = [];
                foreach ($months as $month) {
                    $monthly[$month] = round((float) ($byMonth[$month] ?? 0), 2);
                }

                return [
                    'name' => $name,
                    'color' => $group->first()['color'],
                    'monthly' => $monthly,
                    'total' => round($group->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Fluxo de caixa total (todo o período) por categoria, separando receita e despesa.
     */
    private function cashflowByCategory(Collection $expenses, Collection $income): array
    {
        $expenseRows = $expenses
            ->groupBy('name')
            ->map(fn (Collection $g, string $name) => [
                'name' => $name,
                'color' => $g->first()['color'],
                'kind' => 'despesa',
                'total' => round($g->sum('amount'), 2),
            ])
            ->values();

        $incomeRows = $income
            ->groupBy('name')
            ->map(fn (Collection $g, string $name) => [
                'name' => $name,
                'color' => $g->first()['color'],
                'kind' => 'receita',
                'total' => round($g->sum('amount'), 2),
            ])
            ->values();

        return $incomeRows
            ->sortByDesc('total')
            ->concat($expenseRows->sortByDesc('total'))
            ->values()
            ->all();
    }
}
