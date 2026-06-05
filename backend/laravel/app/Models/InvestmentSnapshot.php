<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentSnapshot extends Model
{
    protected $fillable = [
        'investment_id',
        'snapshot_date',
        'applied_amount',
        'current_amount',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'applied_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}
