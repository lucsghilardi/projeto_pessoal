<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentContribution extends Model
{
    protected $fillable = [
        'investment_id',
        'amount',
        'contributed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'contributed_at' => 'date',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}
