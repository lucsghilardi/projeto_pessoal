<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeLembrete extends Model
{
    protected $table = 'saude_lembretes';

    protected $fillable = [
        'user_id',
        'suplemento_id',
        'data',
        'enviado_em',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'enviado_em' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function suplemento(): BelongsTo
    {
        return $this->belongsTo(SaudeSuplemento::class, 'suplemento_id');
    }
}
