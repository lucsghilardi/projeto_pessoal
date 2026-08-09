<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma noite de sono. `data` é o dia em que se acordou (convenção do Garmin).
 * `origem = manual` marca a noite corrigida à mão — a importação não sobrescreve
 * essas, senão o job horário desfaria a correção na passada seguinte.
 */
class SaudeSono extends Model
{
    protected $table = 'saude_sonos';

    protected $fillable = [
        'user_id',
        'data',
        'duracao_min',
        'profundo_min',
        'leve_min',
        'rem_min',
        'acordado_min',
        'cochilo_min',
        'despertares',
        'score',
        'score_qualificador',
        'inicio',
        'fim',
        'hrv_medio',
        'estresse_medio',
        'origem',
        'observacao',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'duracao_min' => 'integer',
        'profundo_min' => 'integer',
        'leve_min' => 'integer',
        'rem_min' => 'integer',
        'acordado_min' => 'integer',
        'cochilo_min' => 'integer',
        'despertares' => 'integer',
        'score' => 'integer',
        'hrv_medio' => 'decimal:1',
        'estresse_medio' => 'decimal:1',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
