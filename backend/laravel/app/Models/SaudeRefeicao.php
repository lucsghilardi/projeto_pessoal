<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaudeRefeicao extends Model
{
    protected $table = 'saude_refeicoes';

    protected $fillable = [
        'user_id',
        'data',
        'horario',
        'nome',
        'tipo',
        'itens',
        'calorias',
        'proteinas_g',
        'carboidratos_g',
        'gorduras_g',
        'confianca',
        'origem',
        'whatsapp_mensagem_id',
        'foto_path',
        'observacao',
    ];

    protected $casts = [
        'data' => 'date:Y-m-d',
        'itens' => 'array',
        'calorias' => 'integer',
        'proteinas_g' => 'decimal:1',
        'carboidratos_g' => 'decimal:1',
        'gorduras_g' => 'decimal:1',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(WhatsappMensagem::class, 'whatsapp_mensagem_id');
    }
}
