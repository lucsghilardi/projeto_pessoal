<?php

namespace App\Jobs;

use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\WhatsappRelatorioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Relatório de fim de dia: roda pelo scheduler (routes/console.php) e gera o
 * relatório de cada usuário com instância ativa, enviando pelo WhatsApp.
 */
class GerarRelatorioDiario implements ShouldQueue
{
    use Queueable;

    public int $timeout = 570;

    public function handle(WhatsappRelatorioService $service): void
    {
        WhatsappInstancia::with('user')
            ->where('relatorio_diario_ativo', true)
            ->get()
            ->each(function (WhatsappInstancia $instancia) use ($service) {
                if ($instancia->user === null) {
                    return;
                }
                try {
                    $service->gerarEEnviar($instancia->user, 'diario');
                } catch (\Throwable $e) {
                    Log::error("[whatsapp:relatorio] falha no diário do usuário {$instancia->user_id}: ".$e->getMessage());
                }
            });
    }
}
