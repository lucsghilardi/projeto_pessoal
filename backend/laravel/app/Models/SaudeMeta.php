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
        'sexo',
        'data_nascimento',
        'nivel_atividade',
        'gasto_dinamico',
        'fator_base',
        'calorias_alvo',
        'proteinas_alvo_g',
    ];

    protected $casts = [
        'peso_meta_kg' => 'decimal:2',
        'data_alvo' => 'date:Y-m-d',
        'altura_cm' => 'integer',
        'data_nascimento' => 'date:Y-m-d',
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
