<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaudeCardioSessao extends Model
{
    protected $table = 'saude_cardio_sessoes';

    protected $fillable = [
        'user_id',
        'treino_id',
        'data',
        'horario',
        'nome',
        'modalidade',
        'duracao_min',
        'distancia_km',
        'calorias',
        'fc_media',
        'fc_maxima',
        'intensidade',
        'origem',
        'garmin_activity_id',
        'observacao',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'duracao_min' => 'integer',
        'distancia_km' => 'decimal:2',
        'calorias' => 'integer',
        'fc_media' => 'integer',
        'fc_maxima' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ficha que prescreveu o cardio (null quando é avulso). */
    public function treino(): BelongsTo
    {
        return $this->belongsTo(SaudeTreino::class, 'treino_id');
    }

    /** Splits e zonas de FC. Null enquanto ninguém abriu a corrida no painel. */
    public function detalhe(): HasOne
    {
        return $this->hasOne(SaudeCardioDetalhe::class, 'cardio_sessao_id');
    }

    public function analise(): HasOne
    {
        return $this->hasOne(SaudeCardioAnalise::class, 'cardio_sessao_id');
    }
}
