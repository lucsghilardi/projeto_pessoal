<?php

namespace App\Jobs;

use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\EvolutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Vigia as sessões do WhatsApp e reergue as que morreram calada.
 *
 * O modo de falha é sempre o mesmo (24/08 e 28/08 de 2026): o socket do Baileys
 * cai sem emitir connection.update, então a Evolution deixa connectionStatus
 * preso em 'open' para sempre. A instância para de receber webhook e de enviar,
 * mas se anuncia saudável — em 28/08 ficou dois dias parada sem ninguém notar,
 * porque a detecção só rodava quando alguém abria a tela do painel.
 *
 * Aqui a sonda roda sozinha. Confirmado o socket morto, tenta o restart da
 * instância, que reaproveita a credencial já pareada e não pede QR — foi o que
 * recuperou a sessão em 30/08 sem intervenção manual.
 */
class VerificarSessaoWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        foreach (WhatsappInstancia::query()->get() as $instancia) {
            $this->verificar($instancia);
        }
    }

    private function verificar(WhatsappInstancia $instancia): void
    {
        $svc = EvolutionService::forInstancia($instancia);
        if (! $svc->configOk()) {
            return;
        }

        $nome = $instancia->instance_name;

        // A sonda só devolve true diante do 428/"Connection Closed" explícito;
        // timeout e 5xx mantêm o status otimista, para um soluço de rede não
        // disparar um restart desnecessário.
        if (! $svc->socketMorto()) {
            return;
        }

        Log::warning("[whatsapp:vigia] socket de {$nome} não responde (sessão zumbi); tentando restart da instância.");
        $instancia->update(['status' => 'desconectado']);

        $resp = $svc->restartInstance();
        if (! ($resp['sucesso'] ?? false)) {
            Log::error("[whatsapp:vigia] restart de {$nome} falhou: ".($resp['erro'] ?? 'erro desconhecido').' — é preciso reparear pelo QR code.');

            return;
        }

        // O socket novo não sobe instantaneamente. Sem esta folga a sonda
        // seguinte pega a instância no meio do handshake e conclui, errado,
        // que o restart não adiantou.
        sleep((int) config('whatsapp.evolution.espera_restart_segundos'));

        if ($svc->socketMorto(ignorarCache: true)) {
            Log::error("[whatsapp:vigia] {$nome} continua sem responder depois do restart; é preciso reparear pelo QR code.");

            return;
        }

        $instancia->update(['status' => 'conectado']);
        Log::warning("[whatsapp:vigia] sessão de {$nome} restaurada pelo restart da instância.");
    }
}
