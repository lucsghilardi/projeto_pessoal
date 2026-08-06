<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
