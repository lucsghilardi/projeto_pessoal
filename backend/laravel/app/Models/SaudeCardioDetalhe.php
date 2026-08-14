<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalhe de uma atividade do Garmin (splits, zonas de FC e métricas finas).
 * Buscado sob demanda: a ausência da linha significa "ainda não sincronizado".
 */
class SaudeCardioDetalhe extends Model
{
    protected $table = 'saude_cardio_detalhes';

    protected $fillable = [
        'cardio_sessao_id',
        'garmin_activity_id',
        'splits',
        'zonas_fc',
        'duracao_seg',
        'tempo_movimento_seg',
        'distancia_m',
        'passos',
        'cadencia_media',
        'passada_media_cm',
        'potencia_media',
        'fc_minima',
        'elevacao_ganho_m',
        'elevacao_perda_m',
        'training_effect_aerobico',
        'training_effect_anaerobico',
        'vo2max',
        'sincronizado_em',
    ];

    protected $casts = [
        'splits' => 'array',
        'zonas_fc' => 'array',
        'duracao_seg' => 'integer',
        'tempo_movimento_seg' => 'integer',
        'distancia_m' => 'integer',
        'passos' => 'integer',
        'cadencia_media' => 'integer',
        'passada_media_cm' => 'integer',
        'potencia_media' => 'integer',
        'fc_minima' => 'integer',
        'elevacao_ganho_m' => 'integer',
        'elevacao_perda_m' => 'integer',
        'training_effect_aerobico' => 'decimal:1',
        'training_effect_anaerobico' => 'decimal:1',
        'vo2max' => 'decimal:1',
        'sincronizado_em' => 'datetime',
    ];

    public function sessao(): BelongsTo
    {
        return $this->belongsTo(SaudeCardioSessao::class, 'cardio_sessao_id');
    }

    /**
     * Pace médio em segundos por km, a partir da duração e distância exatas.
     * Null quando a atividade não tem distância (esteira sem sensor, por exemplo).
     */
    public function paceSegPorKm(): ?int
    {
        if (! $this->duracao_seg || ! $this->distancia_m) {
            return null;
        }

        return (int) round($this->duracao_seg / ($this->distancia_m / 1000));
    }
}
