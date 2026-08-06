<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumo diário importado do relógio. `calorias_ativas` cobre o dia inteiro
 * (inclusive os passos fora de atividades registradas) e por isso é a fonte
 * preferida de gasto para o TDEE dinâmico.
 */
class SaudeDiaGarmin extends Model
{
    protected $table = 'saude_dias_garmin';

    protected $fillable = [
        'user_id',
        'data',
        'passos',
        'calorias_ativas',
        'calorias_totais',
        'fc_repouso',
        'minutos_intensidade',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'passos' => 'integer',
        'calorias_ativas' => 'integer',
        'calorias_totais' => 'integer',
        'fc_repouso' => 'integer',
        'minutos_intensidade' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
