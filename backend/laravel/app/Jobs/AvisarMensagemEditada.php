<?php

namespace App\Jobs;

use App\Models\WhatsappMensagem;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Avisa no seu próprio número que um contato editou uma mensagem, com as duas
 * versões lado a lado — o "antes" só existe aqui, já que o WhatsApp troca o
 * texto em todos os aparelhos e guarda a versão anterior atrás de um menu.
 *
 * Quem decide se o aviso sai é o WhatsappIngestService::marcarEditada (grupos e
 * edições suas ficam de fora); aqui só revalidamos o que pode ter mudado entre
 * o webhook e a vez do job na fila.
 *
 * O texto anterior vem por parâmetro porque a coluna já foi sobrescrita — e numa
 * segunda edição o "antes" é a versão que acabou de sair, não a original.
 *
 * tries = 1: um aviso perdido é melhor que o mesmo aviso repetido, e o texto
 * novo já está gravado — uma retentativa não teria como se reconhecer.
 */
class AvisarMensagemEditada implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $mensagemId, public string $textoAnterior) {}

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

        if (! $instancia->aviso_edicoes_ativo) {
            return;
        }

        $sender->enviarParaMim($instancia, $this->montarMensagem($mensagem));
    }

    private function montarMensagem(WhatsappMensagem $mensagem): string
    {
        // O horário é o do envio original, não o da edição: é assim que você
        // acha a mensagem na conversa.
        $quando = $mensagem->momment > 0
            ? now()->setTimestamp(intdiv($mensagem->momment, 1000))
            : $mensagem->created_at ?? now();
        $quando = $quando->setTimezone((string) config('whatsapp.relatorio.timezone'));

        return implode("\n", [
            '✏️ *Mensagem editada*',
            '',
            '*'.$mensagem->chat->nomeExibicao().'* — '.$quando->format('d/m').' às '.$quando->format('H:i'),
            '',
            '*Antes:*',
            $this->corpo($this->textoAnterior, $mensagem),
            '',
            '*Agora:*',
            $this->corpo((string) $mensagem->texto, $mensagem),
        ]);
    }

    /**
     * Negrito e itálico do WhatsApp não atravessam quebra de linha, então nada
     * de riscar o texto antigo: as duas versões vão cruas, sob rótulos.
     */
    private function corpo(string $texto, WhatsappMensagem $mensagem): string
    {
        $texto = trim($texto);

        // Mídia já chega com texto sintético do normalizador ([Imagem], [Áudio]);
        // vazio mesmo só em tipos que não sabemos descrever.
        return $texto !== ''
            ? Str::limit($texto, 500)
            : '_(sem texto — '.$mensagem->tipo.')_';
    }
}
