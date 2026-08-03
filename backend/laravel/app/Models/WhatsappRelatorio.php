<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappRelatorio extends Model
{
    protected $table = 'whatsapp_relatorios';

    protected $fillable = [
        'user_id',
        'tipo',
        'referencia_data',
        'dados',
        'texto_whatsapp',
        'enviado_em',
    ];

    protected $casts = [
        'referencia_data' => 'date',
        'dados' => 'array',
        'enviado_em' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sugestoes(): HasMany
    {
        return $this->hasMany(WhatsappSugestao::class, 'relatorio_id');
    }
}
