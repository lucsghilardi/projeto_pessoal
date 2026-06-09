<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Receivable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReceivableController extends Controller
{
    private const FIXED_MONTHS = 12;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        [$start, $end] = $this->monthRange($request->query('month'));

        $items = Receivable::query()
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
            'kind' => ['required', Rule::in(['avulsa', 'fixa'])],
        ]);

        $userId = $request->user()->id;
        $first = Carbon::parse($data['due_date'])->startOfDay();

        $base = [
            'user_id' => $userId,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'],
            'amount' => $data['amount'],
        ];

        $count = 0;

        if ($data['kind'] === 'fixa') {
            $group = (string) Str::uuid();
            for ($i = 0; $i < self::FIXED_MONTHS; $i++) {
                Receivable::create([
                    ...$base,
                    // NoOverflow: data dia 31 cai no último dia dos meses mais curtos.
                    'due_date' => $first->copy()->addMonthsNoOverflow($i),
                    'kind' => 'fixa',
                    'group_id' => $group,
                ]);
                $count++;
            }
        } else {
            Receivable::create([...$base, 'due_date' => $first, 'kind' => 'avulsa']);
            $count = 1;
        }

        return response()->json(['message' => 'Recebimento(s) criado(s).', 'created' => $count], 201);
    }

    public function update(Request $request, Receivable $receivable): JsonResponse
    {
        $this->authorizeOwnership($request, $receivable);

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

        DB::transaction(function () use ($receivable, $data) {
            // Se já foi recebido, ajusta o saldo creditado pela diferença do novo valor.
            if ($receivable->is_received && $receivable->bank_account_id) {
                $difference = round((float) $data['amount'] - (float) $receivable->amount, 2);

                if ($difference !== 0.0) {
                    BankAccount::whereKey($receivable->bank_account_id)->increment('balance', $difference);
                }
            }

            $receivable->update([
                'description' => $data['description'],
                'category_id' => $data['category_id'] ?? null,
                'amount' => $data['amount'],
                'due_date' => $data['due_date'],
            ]);
        });

        return response()->json($receivable->fresh()->load(['category:id,name,color,kind', 'bankAccount:id,name']));
    }

    /**
     * Marca como recebido, escolhe a conta de destino e CREDITA o saldo dela.
     */
    public function receive(Request $request, Receivable $receivable): JsonResponse
    {
        $this->authorizeOwnership($request, $receivable);

        $data = $request->validate([
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $request->user()->id),
            ],
            'received_at' => ['nullable', 'date'],
        ]);

        if ($receivable->is_received) {
            return response()->json(['message' => 'Este recebimento já foi confirmado.'], 422);
        }

        DB::transaction(function () use ($receivable, $data) {
            BankAccount::whereKey($data['bank_account_id'])->increment('balance', $receivable->amount);

            $receivable->update([
                'is_received' => true,
                'received_at' => $data['received_at'] ?? now()->toDateString(),
                'bank_account_id' => $data['bank_account_id'],
            ]);
        });

        return response()->json($receivable->fresh()->load(['category:id,name,color,kind', 'bankAccount:id,name']));
    }

    /**
     * Estorna o recebimento: retira o valor do saldo da conta e volta a pendente.
     */
    public function unreceive(Request $request, Receivable $receivable): JsonResponse
    {
        $this->authorizeOwnership($request, $receivable);

        if (! $receivable->is_received) {
            return response()->json($receivable->load(['category:id,name,color,kind', 'bankAccount:id,name']));
        }

        DB::transaction(function () use ($receivable) {
            if ($receivable->bank_account_id) {
                BankAccount::whereKey($receivable->bank_account_id)->decrement('balance', $receivable->amount);
            }

            $receivable->update([
                'is_received' => false,
                'received_at' => null,
                'bank_account_id' => null,
            ]);
        });

        return response()->json($receivable->fresh()->load(['category:id,name,color,kind', 'bankAccount:id,name']));
    }

    public function destroy(Request $request, Receivable $receivable): JsonResponse
    {
        $this->authorizeOwnership($request, $receivable);

        $userId = $request->user()->id;

        if ($request->query('scope') === 'group' && $receivable->group_id) {
            DB::transaction(function () use ($userId, $receivable) {
                $items = Receivable::query()
                    ->where('user_id', $userId)
                    ->where('group_id', $receivable->group_id)
                    ->get();

                foreach ($items as $item) {
                    $this->refundIfReceived($item);
                }

                Receivable::query()->where('user_id', $userId)->where('group_id', $receivable->group_id)->delete();
            });

            return response()->json(['message' => 'Lançamentos do grupo removidos.']);
        }

        DB::transaction(function () use ($receivable) {
            $this->refundIfReceived($receivable);
            $receivable->delete();
        });

        return response()->json(['message' => 'Lançamento removido.']);
    }

    /**
     * Retira o valor do saldo da conta quando um recebimento confirmado é removido.
     */
    private function refundIfReceived(Receivable $receivable): void
    {
        if ($receivable->is_received && $receivable->bank_account_id) {
            BankAccount::whereKey($receivable->bank_account_id)->decrement('balance', $receivable->amount);
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

    private function authorizeOwnership(Request $request, Receivable $receivable): void
    {
        abort_unless((int) $receivable->user_id === (int) $request->user()->id, 403);
    }
}
