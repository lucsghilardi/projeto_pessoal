<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Payable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PayableController extends Controller
{
    private const FIXED_MONTHS = 12;

    public function index(Request $request): JsonResponse
    {
        [$start, $end] = $this->monthRange($request->query('month'));

        $items = Payable::query()
            ->with(['category:id,name,color,kind', 'bankAccount:id,name'])
            ->where('user_id', $request->user()->id)
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')
            ->orderBy('description')
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $request->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['required', 'date'],
            'kind' => ['required', Rule::in(['avulsa', 'fixa', 'parcelada'])],
            'installments_total' => ['required_if:kind,parcelada', 'nullable', 'integer', 'min:2', 'max:360'],
        ]);

        $userId = $request->user()->id;
        $first = Carbon::parse($data['due_date'])->startOfDay();
        $kind = $data['kind'];

        $base = [
            'user_id' => $userId,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'],
            'amount' => $data['amount'],
        ];

        $count = 0;

        if ($kind === 'parcelada') {
            $total = (int) $data['installments_total'];
            $group = (string) Str::uuid();
            for ($i = 0; $i < $total; $i++) {
                Payable::create([
                    ...$base,
                    'due_date' => $first->copy()->addMonths($i),
                    'kind' => 'parcelada',
                    'installment_number' => $i + 1,
                    'installments_total' => $total,
                    'group_id' => $group,
                ]);
                $count++;
            }
        } elseif ($kind === 'fixa') {
            $group = (string) Str::uuid();
            for ($i = 0; $i < self::FIXED_MONTHS; $i++) {
                Payable::create([
                    ...$base,
                    'due_date' => $first->copy()->addMonths($i),
                    'kind' => 'fixa',
                    'group_id' => $group,
                ]);
                $count++;
            }
        } else {
            Payable::create([
                ...$base,
                'due_date' => $first,
                'kind' => 'avulsa',
            ]);
            $count = 1;
        }

        return response()->json(['message' => 'Conta(s) criada(s).', 'created' => $count], 201);
    }

    public function update(Request $request, Payable $payable): JsonResponse
    {
        $this->authorizeOwnership($request, $payable);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $request->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['required', 'date'],
        ]);

        $payable->update([
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'amount' => $data['amount'],
            'due_date' => $data['due_date'],
        ]);

        return response()->json($payable->fresh()->load('category:id,name,color,kind'));
    }

    /**
     * Dá baixa: marca como paga, escolhe a conta de origem e DEBITA o saldo dela.
     */
    public function pay(Request $request, Payable $payable): JsonResponse
    {
        $this->authorizeOwnership($request, $payable);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $request->user()->id),
            ],
            'paid_at' => ['nullable', 'date'],
        ]);

        if ($payable->is_paid) {
            return response()->json(['message' => 'Esta conta já está paga.'], 422);
        }

        DB::transaction(function () use ($payable, $data) {
            BankAccount::whereKey($data['bank_account_id'])->decrement('balance', $payable->amount);

            $payable->update([
                'is_paid' => true,
                'paid_at' => $data['paid_at'] ?? now()->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
            ]);
        });

        return response()->json($payable->fresh()->load(['category:id,name,color,kind', 'bankAccount:id,name']));
    }

    /**
     * Estorna a baixa: devolve o valor ao saldo da conta usada e volta a pendente.
     */
    public function unpay(Request $request, Payable $payable): JsonResponse
    {
        $this->authorizeOwnership($request, $payable);

        if (! $payable->is_paid) {
            return response()->json($payable->load(['category:id,name,color,kind', 'bankAccount:id,name']));
        }

        DB::transaction(function () use ($payable) {
            if ($payable->bank_account_id) {
                BankAccount::whereKey($payable->bank_account_id)->increment('balance', $payable->amount);
            }

            $payable->update([
                'is_paid' => false,
                'paid_at' => null,
                'bank_account_id' => null,
            ]);
        });

        return response()->json($payable->fresh()->load(['category:id,name,color,kind', 'bankAccount:id,name']));
    }

    public function destroy(Request $request, Payable $payable): JsonResponse
    {
        $this->authorizeOwnership($request, $payable);

        $userId = $request->user()->id;

        if ($request->query('scope') === 'group' && $payable->group_id) {
            DB::transaction(function () use ($userId, $payable) {
                $items = Payable::query()
                    ->where('user_id', $userId)
                    ->where('group_id', $payable->group_id)
                    ->get();

                foreach ($items as $item) {
                    $this->refundIfPaid($item);
                }

                Payable::query()->where('user_id', $userId)->where('group_id', $payable->group_id)->delete();
            });

            return response()->json(['message' => 'Lançamentos do grupo removidos.']);
        }

        DB::transaction(function () use ($payable) {
            $this->refundIfPaid($payable);
            $payable->delete();
        });

        return response()->json(['message' => 'Lançamento removido.']);
    }

    /**
     * Devolve o valor ao saldo da conta quando um lançamento pago é removido.
     */
    private function refundIfPaid(Payable $payable): void
    {
        if ($payable->is_paid && $payable->bank_account_id) {
            BankAccount::whereKey($payable->bank_account_id)->increment('balance', $payable->amount);
        }
    }

    private function monthRange(?string $month): array
    {
        $base = $month ? Carbon::parse($month.'-01') : now();

        return [
            $base->copy()->startOfMonth()->toDateString(),
            $base->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function authorizeOwnership(Request $request, Payable $payable): void
    {
        abort_unless((int) $payable->user_id === (int) $request->user()->id, 403);
    }
}
