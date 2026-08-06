<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeExercicio extends Model
{
    protected $table = 'saude_exercicios';

    protected $fillable = [
        'user_id',
        'treino_id',
        'nome',
        'series',
        'repeticoes',
        'carga',
        'observacoes',
        'posicao',
    ];

    protected $casts = [
        'series' => 'integer',
        'posicao' => 'integer',
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
