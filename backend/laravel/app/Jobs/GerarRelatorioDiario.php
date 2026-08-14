<?php

namespace App\Jobs;

use App\Models\WhatsappInstancia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Relatório de fim de dia: roda pelo scheduler (routes/console.php) e enfileira
 * um envio por usuário com instância ativa.
 */
class GerarRelatorioDiario implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        WhatsappInstancia::query()
            ->deUsuarioAtivo()
            ->where('relatorio_diario_ativo', true)
            ->pluck('user_id')
            ->each(fn (int $userId) => EnviarRelatorioWhatsapp::dispatch($userId, 'diario'));
    }
}
