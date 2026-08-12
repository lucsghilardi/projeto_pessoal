<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado da conversa do chat-consigo-mesmo. Ver WhatsappConversaService — nada
 * fora dele deveria escrever nesta tabela (as transições precisam do lock).
 */
class WhatsappConversa extends Model
{
    protected $table = 'whatsapp_conversas';

    public const OCIOSO = 'ocioso';

    public const AGUARDANDO_TIPO_MIDIA = 'aguardando_tipo_midia';

    public const AGUARDANDO_CONFIRMACAO = 'aguardando_confirmacao';

    public const AGUARDANDO_DESTINO = 'aguardando_destino';

    public const AGUARDANDO_ACAO = 'aguardando_acao';

    public const PROCESSANDO = 'processando';

    public const FLUXO_TAREFA = 'tarefa';

    public const FLUXO_REFEICAO = 'refeicao';

    public const FLUXO_COMPROVANTE = 'comprovante';

    protected $fillable = [
        'instancia_id',
        'chat_id',
        'estado',
        'fluxo',
        'mensagem_id',
        'anexo_path',
        'payload',
        'tentativas',
        'expira_em',
    ];

    protected $casts = [
        'payload' => 'array',
        'tentativas' => 'integer',
        'expira_em' => 'datetime',
    ];

    public function instancia(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstancia::class, 'instancia_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappChat::class, 'chat_id');
    }

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(WhatsappMensagem::class, 'mensagem_id');
    }

    public function estaOciosa(): bool
    {
        return $this->estado === self::OCIOSO;
    }

    public function expirou(): bool
    {
        return $this->expira_em !== null && $this->expira_em->isPast();
    }
}
