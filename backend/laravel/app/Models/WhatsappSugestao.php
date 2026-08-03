<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappSugestao extends Model
{
    protected $table = 'whatsapp_sugestoes';

    protected $fillable = [
        'user_id',
        'chat_id',
        'relatorio_id',
        'origem',
        'titulo',
        'descricao',
        'prioridade',
        'due_date',
        'contexto',
        'status',
        'task_id',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappChat::class, 'chat_id');
    }

    public function relatorio(): BelongsTo
    {
        return $this->belongsTo(WhatsappRelatorio::class, 'relatorio_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
