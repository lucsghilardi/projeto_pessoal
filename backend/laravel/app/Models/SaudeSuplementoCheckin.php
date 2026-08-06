<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeSuplementoCheckin extends Model
{
    protected $table = 'saude_suplemento_checkins';

    protected $fillable = [
        'user_id',
        'suplemento_id',
        'data',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
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
