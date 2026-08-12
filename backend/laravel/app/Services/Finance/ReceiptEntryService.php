<?php

namespace App\Services\Finance;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\Payable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Grava UM lançamento revisado a partir de um comprovante, no cartão de crédito
 * ou na conta bancária. Extraído do AiReceiptController para ser reusado pelo
 * fluxo de comprovante do WhatsApp — o lote (fatura/extrato) continua no
 * controller, porque só existe no painel.
 *
 * Diferente do controller, aqui não há FormRequest garantindo a posse dos ids:
 * conta e cartão são sempre filtrados por user_id.
 */
class ReceiptEntryService
{
    /**
     * @param  array{
     *     destination: string,
     *     description: string,
     *     amount: float|int|string,
     *     date: string,
     *     category_id?: int|null,
     *     credit_card_id?: int|null,
     *     installments_total?: int|null,
     *     reference_month?: string|null,
     *     bank_account_id?: int|null
     * }  $dados
     * @return array{tipo: string, quantidade: int, fingerprint: string}
     */
    public function lancar(int $userId, array $dados, string $receiptPath): array
    {
        $fingerprint = self::fingerprint($dados['description'], $dados['amount'], $dados['date']);

        $quantidade = $dados['destination'] === 'cartao'
            ? $this->storeCardTransaction($userId, $dados, $receiptPath, $fingerprint)
            : $this->storePayable($userId, $dados, $receiptPath, $fingerprint);

        return [
            'tipo' => $dados['destination'],
            'quantidade' => $quantidade,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Cria a(s) transação(ões) de cartão na fatura correta — espelha CreditCardTransactionController::store.
     *
     * @param  array<string, mixed>  $dados
     */
    public function storeCardTransaction(int $userId, array $dados, string $receiptPath, string $fingerprint): int
    {
        $card = CreditCard::query()->where('user_id', $userId)->findOrFail($dados['credit_card_id']);
        $purchase = Carbon::parse($dados['date'])->startOfDay();
        $total = (int) ($dados['installments_total'] ?? 1);

        $firstRef = $dados['reference_month'] ?? $card->resolveInvoiceWindow($purchase)['reference_month'];

        $base = [
            'user_id' => $userId,
            'credit_card_id' => $card->id,
            'category_id' => $dados['category_id'] ?? null,
            'description' => $dados['description'],
            'amount' => $dados['amount'],
            'purchase_date' => $purchase->toDateString(),
            'receipt_path' => $receiptPath,
            'import_fingerprint' => $fingerprint,
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

        return $count;
    }

    /**
     * Cria a conta a pagar JÁ PAGA, debitando o saldo da conta — espelha PayableController::pay.
     *
     * @param  array<string, mixed>  $dados
     */
    public function storePayable(int $userId, array $dados, string $receiptPath, string $fingerprint): int
    {
        $date = Carbon::parse($dados['date'])->startOfDay();

        DB::transaction(function () use ($userId, $dados, $receiptPath, $fingerprint, $date) {
            // Escopo por user_id: sem FormRequest garantindo a posse (fluxo do WhatsApp),
            // um id de conta alheia debitaria o saldo de outra pessoa.
            $debitadas = BankAccount::query()
                ->where('user_id', $userId)
                ->whereKey($dados['bank_account_id'])
                ->decrement('balance', $dados['amount']);

            if ($debitadas === 0) {
                throw new RuntimeException('Conta bancária não encontrada.');
            }

            Payable::create([
                'user_id' => $userId,
                'category_id' => $dados['category_id'] ?? null,
                'description' => $dados['description'],
                'amount' => $dados['amount'],
                'due_date' => $date->toDateString(),
                'kind' => 'avulsa',
                'is_paid' => true,
                'paid_at' => $date->toDateString(),
                'bank_account_id' => $dados['bank_account_id'],
                'receipt_path' => $receiptPath,
                'import_fingerprint' => $fingerprint,
            ]);
        });

        return 1;
    }

    /**
     * Verifica se um lançamento avulso idêntico já existe no destino escolhido.
     *
     * @param  array<string, mixed>  $dados
     */
    public function isDuplicate(int $userId, array $dados, string $fingerprint): bool
    {
        if ($dados['destination'] === 'cartao') {
            return CreditCardTransaction::query()
                ->where('credit_card_id', $dados['credit_card_id'])
                ->where('import_fingerprint', $fingerprint)
                ->exists();
        }

        return Payable::query()
            ->where('user_id', $userId)
            ->where('import_fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Impressão digital de um lançamento para detectar duplicatas (descrição + valor + data).
     */
    public static function fingerprint(?string $description, mixed $amount, ?string $date): string
    {
        $normalizedDate = $date ? Carbon::parse($date)->toDateString() : '';

        return sha1(
            Str::lower(trim((string) $description))
            .'|'.number_format((float) $amount, 2, '.', '')
            .'|'.$normalizedDate
        );
    }

    public function shiftReference(string $reference, int $months): string
    {
        [$year, $month] = array_map('intval', explode('-', $reference));

        return Carbon::create($year, $month, 1)->addMonthsNoOverflow($months)->format('Y-m');
    }
}
