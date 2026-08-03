<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMensagem extends Model
{
    protected $table = 'whatsapp_mensagens';

    protected $fillable = [
        'chat_id',
        'instancia_id',
        'message_id',
        'phone',
        'from_me',
        'sender_name',
        'tipo',
        'texto',
        'caption',
        'media_url',
        'media_mime',
        'media_filename',
        'status',
        'momment',
        'quoted_message_id',
        'quoted_texto',
        'origem',
        'raw_payload',
    ];

    protected $casts = [
        'from_me' => 'boolean',
        'momment' => 'integer',
        'raw_payload' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappChat::class, 'chat_id');
    }

    public function instancia(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstancia::class, 'instancia_id');
    }
}
