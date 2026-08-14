<?php

namespace App\Jobs;

use App\Models\WhatsappMensagem;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Avisa no seu próprio número que um contato apagou uma mensagem, com o nome de
 * quem apagou e o texto original logo abaixo — a única cópia que sobrou, já que
 * o WhatsApp some com ela em todos os aparelhos.
 *
 * Quem decide se o aviso sai é o WhatsappIngestService::marcarApagada (grupos e
 * exclusões suas ficam de fora); aqui só revalidamos o que pode ter mudado entre
 * o webhook e a vez do job na fila.
 *
 * tries = 1: um aviso perdido é melhor que o mesmo aviso repetido, e a marca
 * apagada_em já foi gravada — uma retentativa não teria como se reconhecer.
 */
class AvisarMensagemApagada implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $mensagemId) {}

    public function handle(WhatsappSender $sender): void
    {
        $mensagem = WhatsappMensagem::with(['chat', 'instancia.user'])->find($this->mensagemId);
        if ($mensagem === null || $mensagem->chat === null) {
            return;
        }

        $instancia = $mensagem->instancia;
        // is_active é checado no login e no EnsureActivePanelUser, que não
        // alcançam a fila: sem isto, desativar alguém deixaria o bot escrevendo.
        if ($instancia === null || $instancia->user === null || ! $instancia->user->is_active) {
            return;
        }

        if (! $instancia->aviso_apagadas_ativo) {
            return;
        }

        $sender->enviarParaMim($instancia, $this->montarMensagem($mensagem));
    }

    private function montarMensagem(WhatsappMensagem $mensagem): string
    {
        $quando = $mensagem->momment > 0
            ? now()->setTimestamp(intdiv($mensagem->momment, 1000))
            : $mensagem->created_at ?? now();
        $quando = $quando->setTimezone((string) config('whatsapp.relatorio.timezone'));

        $texto = trim((string) $mensagem->texto);
        // Mídia já chega com texto sintético do normalizador ([Imagem], [Áudio]);
        // vazio mesmo só em tipos que não sabemos descrever.
        $corpo = $texto !== ''
            ? Str::limit($texto, 500)
            : '_(sem texto — '.$mensagem->tipo.')_';

        return implode("\n", [
            '🗑️ *Mensagem apagada*',
            '',
            '*'.$mensagem->chat->nomeExibicao().'* — '.$quando->format('d/m').' às '.$quando->format('H:i'),
            '',
            $corpo,
        ]);
    }
}
