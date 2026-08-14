<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeMeta extends Model
{
    protected $table = 'saude_metas';

    protected $fillable = [
        'user_id',
        'peso_meta_kg',
        'data_alvo',
        'altura_cm',
        'sono_meta_min',
        'sexo',
        'data_nascimento',
        'nivel_atividade',
        'fc_maxima',
        'vo2max',
        'fc_limiar',
        'previsao_5k_seg',
        'previsao_10k_seg',
        'previsao_21k_seg',
        'previsao_42k_seg',
        'gasto_dinamico',
        'fator_base',
        'calorias_alvo',
        'proteinas_alvo_g',
    ];

    protected $casts = [
        'peso_meta_kg' => 'decimal:2',
        'data_alvo' => 'date:Y-m-d',
        'altura_cm' => 'integer',
        'sono_meta_min' => 'integer',
        'data_nascimento' => 'date:Y-m-d',
        'fc_maxima' => 'integer',
        'vo2max' => 'decimal:1',
        'fc_limiar' => 'integer',
        'previsao_5k_seg' => 'integer',
        'previsao_10k_seg' => 'integer',
        'previsao_21k_seg' => 'integer',
        'previsao_42k_seg' => 'integer',
        'gasto_dinamico' => 'boolean',
        'fator_base' => 'decimal:2',
        'calorias_alvo' => 'integer',
        'proteinas_alvo_g' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
