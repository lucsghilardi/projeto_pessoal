<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leitura da corrida feita pela IA (personal + nutricionista). Uma por sessão;
 * "Refazer análise" sobrescreve a linha.
 */
class SaudeCardioAnalise extends Model
{
    protected $table = 'saude_cardio_analises';

    protected $fillable = [
        'cardio_sessao_id',
        'user_id',
        'dados',
        'modelo',
        'gerado_em',
    ];

    protected $casts = [
        'dados' => 'array',
        'gerado_em' => 'datetime',
    ];

    public function sessao(): BelongsTo
    {
        return $this->belongsTo(SaudeCardioSessao::class, 'cardio_sessao_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
