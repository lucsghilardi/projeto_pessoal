<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
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

        $payTotal = round($payables->sum(fn ($p) => (float) $p->amount), 2);
        $payPaid = round($payables->where('is_paid', true)->sum(fn ($p) => (float) $p->amount), 2);

        $recTotal = round($receivables->sum(fn ($r) => (float) $r->amount), 2);
        $recReceived = round($receivables->where('is_received', true)->sum(fn ($r) => (float) $r->amount), 2);

        $byCategory = $payables
            ->groupBy(fn ($p) => $p->category->name ?? 'Sem categoria')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'color' => $group->first()->category->color ?? '#64748b',
                'total' => round($group->sum(fn ($p) => (float) $p->amount), 2),
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
            'balance_month' => round($recTotal - $payTotal, 2),
            'by_category' => $byCategory,
        ]);
    }
}
