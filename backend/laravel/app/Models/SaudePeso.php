<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudePeso extends Model
{
    protected $table = 'saude_pesos';

    protected $fillable = [
        'user_id',
        'data',
        'peso_kg',
        'observacao',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'peso_kg' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
