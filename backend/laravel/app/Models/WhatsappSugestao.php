<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    /**
     * O usuário já descartou essa mesma sugestão antes? Evita que o relatório
     * diário e as análises fiquem ressuscitando algo que já foi recusado.
     */
    public static function foiDescartada(int $userId, ?int $chatId, string $titulo): bool
    {
        $chave = self::chaveTitulo($titulo);
        if ($chave === '') {
            return false;
        }

        return self::query()
            ->where('user_id', $userId)
            ->where('status', 'descartada')
            ->when($chatId !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('chat_id')->orWhere('chat_id', $chatId),
            ))
            ->pluck('titulo')
            ->contains(fn ($outro) => self::chaveTitulo((string) $outro) === $chave);
    }

    /**
     * Tira da lista o que foi descartado — e as pendentes que só repetem algo
     * já descartado, resquício dos relatórios anteriores a esta regra. Aceita
     * fica: virou tarefa e precisa continuar visível.
     *
     * @template TCollection of \Illuminate\Support\Collection<int, self>
     *
     * @param  TCollection  $sugestoes
     * @return TCollection
     */
    public static function semDescartadas(Collection $sugestoes, int $userId): Collection
    {
        $descartadas = self::chavesDescartadas($userId);

        return $sugestoes
            ->reject(fn (self $s) => $s->status === 'descartada'
                || ($s->status === 'pendente' && in_array(self::chaveTitulo($s->titulo), $descartadas, true)))
            ->values();
    }

    /**
     * Chaves dos títulos que o usuário já descartou.
     *
     * @return array<int, string>
     */
    private static function chavesDescartadas(int $userId): array
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('status', 'descartada')
            ->pluck('titulo')
            ->map(fn ($titulo) => self::chaveTitulo((string) $titulo))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Título sem acento, pontuação ou caixa, para comparar sugestões repetidas. */
    public static function chaveTitulo(string $titulo): string
    {
        return (string) Str::of($titulo)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish();
    }

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
