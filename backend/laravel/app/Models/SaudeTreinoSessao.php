<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeTreinoSessao extends Model
{
    protected $table = 'saude_treino_sessoes';

    protected $fillable = [
        'user_id',
        'treino_id',
        'data',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function treino(): BelongsTo
    {
        return $this->belongsTo(SaudeTreino::class, 'treino_id');
    }
}
