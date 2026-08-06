<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaudeTreino extends Model
{
    protected $table = 'saude_treinos';

    protected $fillable = [
        'user_id',
        'nome',
        'tipo',
        'posicao',
    ];

    protected $casts = [
        'posicao' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exercicios(): HasMany
    {
        return $this->hasMany(SaudeExercicio::class, 'treino_id')->orderBy('posicao');
    }

    public function sessoes(): HasMany
    {
        return $this->hasMany(SaudeTreinoSessao::class, 'treino_id');
    }

    /** Execuções de cardio prescritas por esta ficha. */
    public function cardioSessoes(): HasMany
    {
        return $this->hasMany(SaudeCardioSessao::class, 'treino_id');
    }
}
