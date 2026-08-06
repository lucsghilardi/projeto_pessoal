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
    ];

    protected $casts = [
        'peso_meta_kg' => 'decimal:2',
        'data_alvo' => 'date:Y-m-d',
        'altura_cm' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
