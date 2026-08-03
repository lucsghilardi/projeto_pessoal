<?php

namespace App\Jobs;

use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\WhatsappRelatorioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Briefing matinal: pendências de ontem + follow-ups do dia, no WhatsApp.
 */
class GerarResumoMatinal implements ShouldQueue
{
    use Queueable;

    public int $timeout = 570;

    public function handle(WhatsappRelatorioService $service): void
    {
        WhatsappInstancia::with('user')
            ->where('resumo_matinal_ativo', true)
            ->get()
            ->each(function (WhatsappInstancia $instancia) use ($service) {
                if ($instancia->user === null) {
                    return;
                }
                try {
                    $service->gerarEEnviar($instancia->user, 'matinal');
                } catch (\Throwable $e) {
                    Log::error("[whatsapp:relatorio] falha no matinal do usuário {$instancia->user_id}: ".$e->getMessage());
                }
            });
    }
}
