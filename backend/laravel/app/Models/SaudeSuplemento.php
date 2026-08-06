<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaudeSuplemento extends Model
{
    protected $table = 'saude_suplementos';

    protected $fillable = [
        'user_id',
        'nome',
        'marca',
        'dose',
        'horario',
        'instrucao',
        'observacoes',
        'ativo',
        'posicao',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'posicao' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(SaudeSuplementoCheckin::class, 'suplemento_id');
    }

    public function lembretes(): HasMany
    {
        return $this->hasMany(SaudeLembrete::class, 'suplemento_id');
    }
}
