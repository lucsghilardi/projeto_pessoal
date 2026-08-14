<?php

namespace App\Jobs;

use App\Models\WhatsappInstancia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Briefing matinal: pendências de ontem + follow-ups do dia, no WhatsApp.
 * Enfileira um envio por usuário com instância ativa.
 */
class GerarResumoMatinal implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        WhatsappInstancia::query()
            ->deUsuarioAtivo()
            ->where('resumo_matinal_ativo', true)
            ->pluck('user_id')
            ->each(fn (int $userId) => EnviarRelatorioWhatsapp::dispatch($userId, 'matinal'));
    }
}
