<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investment extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'institution',
        'applied_amount',
        'current_amount',
        'currency',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'applied_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'profit',
        'profit_percent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(InvestmentTag::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(InvestmentSnapshot::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(InvestmentContribution::class);
    }

    public function getProfitAttribute(): float
    {
        return round((float) $this->current_amount - (float) $this->applied_amount, 2);
    }

    public function getProfitPercentAttribute(): float
    {
        $applied = (float) $this->applied_amount;

        if ($applied <= 0) {
            return 0.0;
        }

        return round($this->profit / $applied * 100, 2);
    }
}
