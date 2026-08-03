<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappInstancia extends Model
{
    protected $table = 'whatsapp_instancias';

    protected $fillable = [
        'user_id',
        'apelido',
        'instance_name',
        'phone',
        'status',
        'gtd_ativo',
        'relatorio_diario_ativo',
        'resumo_matinal_ativo',
    ];

    protected $casts = [
        'gtd_ativo' => 'boolean',
        'relatorio_diario_ativo' => 'boolean',
        'resumo_matinal_ativo' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(WhatsappChat::class, 'instancia_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(WhatsappMensagem::class, 'instancia_id');
    }
}
