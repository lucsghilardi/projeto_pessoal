<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\InvestmentSnapshot;
use App\Models\InvestmentTag;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestmentController extends Controller
{
    public const TYPES = [
        'caixinha',
        'poupanca',
        'acoes',
        'fii',
        'fundo',
        'tesouro',
        'cdb',
        'cripto',
        'previdencia',
        'outro',
    ];

    public const CURRENCIES = ['BRL', 'USD'];

    public function index(Request $request): JsonResponse
    {
        $investments = Investment::query()
            ->with('tags:id,name,color')
            ->withSum('contributions as contributed_total', 'amount')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json($investments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $investment = Investment::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'institution' => $data['institution'] ?? null,
            'applied_amount' => $data['applied_amount'],
            'current_amount' => $data['current_amount'],
            'currency' => $data['currency'] ?? 'BRL',
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $investment->tags()->sync($data['tag_ids'] ?? []);
        $this->upsertSnapshot($investment);

        return response()->json($investment->load('tags:id,name,color'), 201);
    }

    public function update(Request $request, Investment $investment): JsonResponse
    {
        $this->authorizeOwnership($request, $investment);

        $data = $this->validateData($request);

        $investment->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'institution' => $data['institution'] ?? null,
            'applied_amount' => $data['applied_amount'],
            'current_amount' => $data['current_amount'],
            'currency' => $data['currency'] ?? 'BRL',
            'notes' => $data['notes'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $investment->tags()->sync($data['tag_ids'] ?? []);
        $this->upsertSnapshot($investment);

        return response()->json($investment->fresh()->load('tags:id,name,color'));
    }

    public function destroy(Request $request, Investment $investment): JsonResponse
    {
        $this->authorizeOwnership($request, $investment);

        $investment->delete();

        return response()->json(['message' => 'Investimento removido com sucesso.']);
    }

    /**
     * Registra um aporte (dinheiro novo): soma no aplicado e no atual e guarda o histórico.
     */
    public function contribute(Request $request, Investment $investment): JsonResponse
    {
        $this->authorizeOwnership($request, $investment);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'contributed_at' => ['nullable', 'date'],
        ]);

        $investment->contributions()->create([
            'amount' => $data['amount'],
            'contributed_at' => $data['contributed_at'] ?? now()->toDateString(),
        ]);

        $investment->applied_amount = round((float) $investment->applied_amount + (float) $data['amount'], 2);
        $investment->current_amount = round((float) $investment->current_amount + (float) $data['amount'], 2);
        $investment->save();

        $this->upsertSnapshot($investment);

        return response()->json(
            $investment->fresh()->load('tags:id,name,color'),
        );
    }

    /**
     * Atualiza em massa apenas o valor atual (saldo de mercado) de vários investimentos.
     */
    public function bulkUpdateValues(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => [
                'required',
                'integer',
                Rule::exists('investments', 'id')->where('user_id', $userId),
            ],
            'items.*.current_amount' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data['items'] as $item) {
            $investment = Investment::query()
                ->where('user_id', $userId)
                ->whereKey($item['id'])
                ->first();

            if (! $investment) {
                continue;
            }

            $investment->current_amount = $item['current_amount'];
            $investment->save();

            $this->upsertSnapshot($investment);
        }

        $investments = Investment::query()
            ->with('tags:id,name,color')
            ->withSum('contributions as contributed_total', 'amount')
            ->where('user_id', $userId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json($investments);
    }

    public function exchangeRate(ExchangeRateService $exchange): JsonResponse
    {
        $data = $exchange->usdToBrl();

        return response()->json([
            'usd_brl' => $data['rate'],
            'available' => $data['available'],
            'fetched_at' => $data['fetched_at'],
        ]);
    }

    public function summary(Request $request, ExchangeRateService $exchange): JsonResponse
    {
        $userId = $request->user()->id;
        $rateInfo = $exchange->usdToBrl();
        $rate = $rateInfo['rate'];

        // Converte qualquer moeda para BRL (moeda base dos totais/graficos).
        $toBrl = static fn (float $value, ?string $currency): float => $currency === 'USD'
            ? $value * $rate
            : $value;

        $investments = Investment::query()
            ->with('tags:id,name,color')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        $applied = round($investments->sum(fn ($i) => $toBrl((float) $i->applied_amount, $i->currency)), 2);
        $current = round($investments->sum(fn ($i) => $toBrl((float) $i->current_amount, $i->currency)), 2);
        $profit = round($current - $applied, 2);
        $profitPercent = $applied > 0 ? round($profit / $applied * 100, 2) : 0.0;

        $byType = $investments
            ->groupBy('type')
            ->map(fn ($group, $type) => [
                'type' => $type,
                'applied' => round($group->sum(fn ($i) => $toBrl((float) $i->applied_amount, $i->currency)), 2),
                'current' => round($group->sum(fn ($i) => $toBrl((float) $i->current_amount, $i->currency)), 2),
                'count' => $group->count(),
            ])
            ->sortByDesc('current')
            ->values();

        $byPurpose = InvestmentTag::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(function ($tag) use ($investments, $toBrl) {
                $items = $investments->filter(fn ($i) => $i->tags->contains('id', $tag->id));

                return [
                    'tag_id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'applied' => round($items->sum(fn ($i) => $toBrl((float) $i->applied_amount, $i->currency)), 2),
                    'current' => round($items->sum(fn ($i) => $toBrl((float) $i->current_amount, $i->currency)), 2),
                    'count' => $items->count(),
                ];
            })
            ->values();

        $currencyById = $investments->pluck('currency', 'id');

        // Evolução do patrimônio: para cada data, soma o valor VIGENTE de cada
        // investimento (último snapshot conhecido até aquela data — carry-forward),
        // e não apenas os investimentos atualizados naquele dia.
        $snapshots = InvestmentSnapshot::query()
            ->whereIn('investment_id', $investments->pluck('id'))
            ->orderBy('snapshot_date')
            ->get(['investment_id', 'snapshot_date', 'applied_amount', 'current_amount']);

        $snapshotsByInvestment = $snapshots->groupBy('investment_id');
        $distinctDates = $snapshots
            ->map(fn ($s) => $s->snapshot_date->toDateString())
            ->unique()
            ->sort()
            ->values();

        $evolution = $distinctDates
            ->map(function ($date) use ($snapshotsByInvestment, $currencyById, $toBrl) {
                $applied = 0.0;
                $current = 0.0;

                foreach ($snapshotsByInvestment as $investmentId => $investmentSnapshots) {
                    $latest = $investmentSnapshots
                        ->filter(fn ($s) => $s->snapshot_date->toDateString() <= $date)
                        ->last();

                    if (! $latest) {
                        continue;
                    }

                    $currency = $currencyById[$investmentId] ?? 'BRL';
                    $applied += $toBrl((float) $latest->applied_amount, $currency);
                    $current += $toBrl((float) $latest->current_amount, $currency);
                }

                return [
                    'date' => $date,
                    'applied' => round($applied, 2),
                    'current' => round($current, 2),
                ];
            })
            ->values();

        return response()->json([
            'totals' => [
                'applied' => $applied,
                'current' => $current,
                'profit' => $profit,
                'profit_percent' => $profitPercent,
                'count' => $investments->count(),
            ],
            'by_type' => $byType,
            'by_purpose' => $byPurpose,
            'evolution' => $evolution,
            'usd_brl' => $rate,
            'usd_brl_available' => $rateInfo['available'],
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'institution' => ['nullable', 'string', 'max:255'],
            'applied_amount' => ['required', 'numeric', 'min:0'],
            'current_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', Rule::in(self::CURRENCIES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('investment_tags', 'id')->where('user_id', $request->user()->id),
            ],
        ]);
    }

    private function upsertSnapshot(Investment $investment): void
    {
        $investment->snapshots()->updateOrCreate(
            ['snapshot_date' => now()->toDateString()],
            [
                'applied_amount' => $investment->applied_amount,
                'current_amount' => $investment->current_amount,
            ],
        );
    }

    private function authorizeOwnership(Request $request, Investment $investment): void
    {
        abort_unless((int) $investment->user_id === (int) $request->user()->id, 403);
    }
}
