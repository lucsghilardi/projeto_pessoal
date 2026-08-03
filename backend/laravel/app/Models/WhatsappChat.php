<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappChat extends Model
{
    protected $table = 'whatsapp_chats';

    protected $fillable = [
        'instancia_id',
        'chave',
        'phone',
        'chat_lid',
        'chat_name',
        'sender_name',
        'photo',
        'is_group',
        'monitorado',
        'monitorado_em',
        'arquivado',
        'last_message_id',
        'last_message_text',
        'last_message_from_me',
        'last_message_at',
        'last_inbound_at',
        'unread_count',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'monitorado' => 'boolean',
        'monitorado_em' => 'datetime',
        'arquivado' => 'boolean',
        'last_message_from_me' => 'boolean',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'unread_count' => 'integer',
    ];

    public function instancia(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstancia::class, 'instancia_id');
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(WhatsappMensagem::class, 'chat_id');
    }

    /** Nome exibível do chat (nome salvo > nome do remetente > telefone). */
    public function nomeExibicao(): string
    {
        return $this->chat_name ?: ($this->sender_name ?: ($this->phone ?: $this->chave));
    }
}
