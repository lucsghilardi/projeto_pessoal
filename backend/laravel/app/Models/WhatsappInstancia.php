<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'calorias_foto_ativo',
        'calorias_texto_ia',
        'financeiro_ativo',
        'relatorio_diario_ativo',
        'resumo_matinal_ativo',
    ];

    protected $casts = [
        'gtd_ativo' => 'boolean',
        'calorias_foto_ativo' => 'boolean',
        'calorias_texto_ia' => 'boolean',
        'financeiro_ativo' => 'boolean',
        'relatorio_diario_ativo' => 'boolean',
        'resumo_matinal_ativo' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Instâncias de quem ainda tem acesso ao painel.
     *
     * O webhook da Evolution é público e os jobs rodam fora de requisição, então
     * `is_active` (checado só no login e no EnsureActivePanelUser) não alcança o
     * assistente: sem este filtro, desativar alguém tirava o painel mas deixava
     * o bot respondendo, criando tarefas e gastando crédito de IA.
     */
    public function scopeDeUsuarioAtivo(Builder $query): Builder
    {
        return $query->whereHas('user', fn (Builder $q) => $q->where('is_active', true));
    }

    public function chats(): HasMany
    {
        return $this->hasMany(WhatsappChat::class, 'instancia_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(WhatsappMensagem::class, 'instancia_id');
    }

    public function conversa(): HasOne
    {
        return $this->hasOne(WhatsappConversa::class, 'instancia_id');
    }
}
