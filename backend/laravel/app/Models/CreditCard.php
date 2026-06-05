<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CreditCard extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'brand',
        'last_four',
        'limit',
        'closing_day',
        'due_day',
        'is_active',
    ];

    protected $casts = [
        'limit' => 'decimal:2',
        'closing_day' => 'integer',
        'due_day' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CreditCardInvoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditCardTransaction::class);
    }

    /**
     * Dada a data de uma compra, descobre a fatura em que ela cai:
     * corte = primeiro dia de corte >= data; vencimento = dia de vencimento no
     * mesmo mês do corte (se due_day > closing_day) ou no mês seguinte.
     *
     * @return array{closing_date: string, due_date: string, reference_month: string}
     */
    public function resolveInvoiceWindow(Carbon $date): array
    {
        $closingDay = (int) $this->closing_day;
        $dueDay = (int) $this->due_day;
        $date = $date->copy()->startOfDay();

        $closing = $this->dayOfMonth($date, $closingDay);
        if ($date->day > $closingDay) {
            $closing = $this->dayOfMonth($date->copy()->addMonthNoOverflow(), $closingDay);
        }

        $due = $dueDay > $closingDay
            ? $this->dayOfMonth($closing, $dueDay)
            : $this->dayOfMonth($closing->copy()->addMonthNoOverflow(), $dueDay);

        return [
            'closing_date' => $closing->toDateString(),
            'due_date' => $due->toDateString(),
            'reference_month' => $due->format('Y-m'),
        ];
    }

    /**
     * Datas de corte/vencimento de uma fatura a partir do mês de referência (YYYY-MM = mês do vencimento),
     * sem persistir nada — usado para exibir faturas ainda vazias ao navegar os meses.
     *
     * @return array{closing_date: string, due_date: string, reference_month: string}
     */
    public function windowForReferenceMonth(string $reference): array
    {
        [$year, $month] = array_map('intval', explode('-', $reference));
        $dueBase = Carbon::create($year, $month, 1)->startOfDay();

        $due = $this->dayOfMonth($dueBase, (int) $this->due_day);
        $closing = (int) $this->due_day > (int) $this->closing_day
            ? $this->dayOfMonth($dueBase, (int) $this->closing_day)
            : $this->dayOfMonth($dueBase->copy()->subMonthNoOverflow(), (int) $this->closing_day);

        return [
            'closing_date' => $closing->toDateString(),
            'due_date' => $due->toDateString(),
            'reference_month' => $reference,
        ];
    }

    /**
     * Encontra (ou cria sob demanda) a fatura de um mês de referência (YYYY-MM = mês do vencimento).
     */
    public function invoiceForReferenceMonth(string $reference): CreditCardInvoice
    {
        $existing = $this->invoices()->where('reference_month', $reference)->first();
        if ($existing) {
            return $existing;
        }

        $window = $this->windowForReferenceMonth($reference);

        return $this->invoices()->create([
            'user_id' => $this->user_id,
            'reference_month' => $reference,
            'closing_date' => $window['closing_date'],
            'due_date' => $window['due_date'],
        ]);
    }

    /**
     * Retorna a data no mês de $base com o dia pedido, limitado ao último dia do mês.
     */
    private function dayOfMonth(Carbon $base, int $day): Carbon
    {
        return $base->copy()->day(min($day, $base->daysInMonth))->startOfDay();
    }
}
