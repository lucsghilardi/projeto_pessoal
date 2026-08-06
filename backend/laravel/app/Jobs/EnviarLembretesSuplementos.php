<?php

namespace App\Jobs;

use App\Models\SaudeLembrete;
use App\Models\SaudeSuplemento;
use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Lembrete de suplementos por WhatsApp: a cada ciclo procura suplementos
 * ativos com horário vencido, sem check-in e sem lembrete no dia, e envia UMA
 * mensagem por usuário com todos os pendentes. O registro em saude_lembretes
 * só é gravado após envio com sucesso — falha reenvia no próximo ciclo; o
 * unique (suplemento, data) garante no máximo um aviso por dia.
 */
class EnviarLembretesSuplementos implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(WhatsappSender $sender): void
    {
        if (! config('saude.lembretes.ativo')) {
            return;
        }

        $agora = now((string) config('saude.timezone'));
        $hoje = $agora->toDateString();

        $pendentes = SaudeSuplemento::query()
            ->where('ativo', true)
            ->where('horario', '<=', $agora->format('H:i:s'))
            ->whereDoesntHave('checkins', fn ($q) => $q->where('data', $hoje))
            ->whereDoesntHave('lembretes', fn ($q) => $q->where('data', $hoje))
            ->orderBy('horario')
            ->get()
            ->groupBy('user_id');

        foreach ($pendentes as $userId => $suplementos) {
            try {
                $instancia = WhatsappInstancia::where('user_id', $userId)->first();
                if ($instancia === null || $instancia->status !== 'conectado') {
                    continue; // sem WhatsApp conectado: tenta de novo no próximo ciclo
                }

                if (! $sender->enviarParaMim($instancia, $this->montarMensagem($suplementos))) {
                    continue;
                }

                foreach ($suplementos as $suplemento) {
                    SaudeLembrete::firstOrCreate(
                        ['suplemento_id' => $suplemento->id, 'data' => $hoje],
                        ['user_id' => $userId, 'enviado_em' => now()],
                    );
                }
            } catch (\Throwable $e) {
                Log::error("[saude:lembretes] falha no usuário {$userId}: ".$e->getMessage());
            }
        }
    }

    private function montarMensagem(Collection $suplementos): string
    {
        $linhas = $suplementos->map(function (SaudeSuplemento $suplemento) {
            $linha = '• '.substr((string) $suplemento->horario, 0, 5).' — '.$suplemento->nome;
            if ($suplemento->instrucao !== null && $suplemento->instrucao !== '') {
                $linha .= ' ('.$suplemento->instrucao.')';
            }

            return $linha;
        })->implode("\n");

        return "💊 *Lembrete de suplementos*\n\nAinda não marcados hoje:\n".$linhas;
    }
}
