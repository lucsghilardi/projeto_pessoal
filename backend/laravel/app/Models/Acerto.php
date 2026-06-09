<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Acerto extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'direction',
        'description',
        'amount',
        'notes',
        'is_settled',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_settled' => 'boolean',
    ];

    // Quanto já foi baixado e quanto falta são calculados a partir das baixas.
    protected $appends = ['settled_amount', 'remaining'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(AcertoSettlement::class);
    }

    public function getSettledAmountAttribute(): float
    {
        return round($this->settlements->sum(fn ($s) => (float) $s->amount), 2);
    }

    public function getRemainingAttribute(): float
    {
        return round((float) $this->amount - $this->settled_amount, 2);
    }
}
