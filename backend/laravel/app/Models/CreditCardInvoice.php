<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardInvoice extends Model
{
    protected $fillable = [
        'user_id',
        'credit_card_id',
        'reference_month',
        'closing_date',
        'due_date',
    ];

    protected $casts = [
        'closing_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
    ];

    // Totais e status são calculados a partir dos lançamentos e pagamentos.
    protected $appends = ['total', 'paid_total', 'remaining', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditCardTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CreditCardInvoicePayment::class);
    }

    public function getTotalAttribute(): float
    {
        return round($this->transactions->sum(fn ($t) => (float) $t->amount), 2);
    }

    public function getPaidTotalAttribute(): float
    {
        return round($this->payments->sum(fn ($p) => (float) $p->amount), 2);
    }

    public function getRemainingAttribute(): float
    {
        return round($this->total - $this->paid_total, 2);
    }

    public function getStatusAttribute(): string
    {
        $total = $this->total;
        $paid = $this->paid_total;

        if ($total > 0 && $paid >= $total) {
            return 'paga';
        }

        return $paid > 0 ? 'parcial' : 'aberta';
    }
}
