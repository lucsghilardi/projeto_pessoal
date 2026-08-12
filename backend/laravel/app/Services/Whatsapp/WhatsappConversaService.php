<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappConversa;
use App\Models\WhatsappInstancia;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Máquina de estados do chat-consigo-mesmo.
 *
 * A transição é SÍNCRONA e transacional (dentro do webhook), com lockForUpdate
 * na linha da conversa; o job só faz o trabalho lento (IA + gravação). Sem isso,
 * duas mensagens em sequência rápida abrem duas propostas, e um "sim" duplicado
 * gera lançamento financeiro em dobro.
 *
 * Envio de mensagem NUNCA acontece dentro da transação: transicionar() devolve
 * a decisão e quem chamou envia/despacha depois do commit.
 */
class WhatsappConversaService
{
    private const DISK = 'local';

    /**
     * Linha permanente da instância — criada uma vez e nunca deletada, para ser
     * um alvo estável de lock.
     */
    public function paraInstancia(WhatsappInstancia $instancia): WhatsappConversa
    {
        return WhatsappConversa::firstOrCreate(
            ['instancia_id' => $instancia->id],
            ['estado' => WhatsappConversa::OCIOSO],
        );
    }

    /**
     * Executa a transição sob lock e devolve o que o callback retornar.
     * O callback recebe a conversa já travada e deve apenas decidir/persistir.
     *
     * @template T
     *
     * @param  Closure(WhatsappConversa): T  $callback
     * @return T
     */
    public function transicionar(WhatsappInstancia $instancia, Closure $callback): mixed
    {
        $this->paraInstancia($instancia);

        return DB::transaction(function () use ($instancia, $callback) {
            $conversa = WhatsappConversa::where('instancia_id', $instancia->id)
                ->lockForUpdate()
                ->first();

            // Corrida no firstOrCreate acima (dois webhooks simultâneos numa
            // instância nova): a outra transação ainda não commitou a linha.
            $conversa ??= new WhatsappConversa([
                'instancia_id' => $instancia->id,
                'estado' => WhatsappConversa::OCIOSO,
            ]);

            // Expirada conta como ociosa: solta o anexo e zera antes de decidir.
            if ($conversa->expirou()) {
                $this->fechar($conversa);
            }

            return $callback($conversa);
        });
    }

    /**
     * Abre (ou substitui) uma pendência. Se havia um anexo de outra pendência,
     * ele é apagado — a nova sempre vence.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function abrir(
        WhatsappConversa $conversa,
        string $estado,
        ?string $fluxo = null,
        ?int $mensagemId = null,
        ?array $payload = null,
        ?string $anexoPath = null,
        ?int $chatId = null,
        ?int $tentativas = null,
    ): WhatsappConversa {
        if ($conversa->anexo_path !== null && $conversa->anexo_path !== $anexoPath) {
            $this->apagarAnexo($conversa->anexo_path);
        }

        $minutos = $estado === WhatsappConversa::PROCESSANDO
            ? (int) config('whatsapp.conversa.ttl_processando_minutos')
            : (int) config('whatsapp.conversa.ttl_minutos');

        $conversa->fill([
            'chat_id' => $chatId ?? $conversa->chat_id,
            'estado' => $estado,
            'fluxo' => $fluxo,
            'mensagem_id' => $mensagemId,
            'anexo_path' => $anexoPath,
            'payload' => $payload,
            'tentativas' => $tentativas ?? 0,
            'expira_em' => now()->addMinutes($minutos),
        ])->save();

        return $conversa;
    }

    /**
     * Marca que um job está agindo sobre a conversa. Enquanto durar, mensagens
     * novas recebem "aguarde" em vez de virarem um segundo commit — é isso que
     * torna o duplo "sim" inofensivo.
     *
     * O estado de origem viaja no payload porque é ele que o job precisa saber
     * para decidir o que fazer com a resposta.
     */
    public function marcarProcessando(WhatsappConversa $conversa, int $mensagemRespostaId): WhatsappConversa
    {
        $payload = $conversa->payload ?? [];
        $payload['_retomar'] = [
            'estado' => $conversa->estado,
            'mensagem_resposta_id' => $mensagemRespostaId,
        ];

        $conversa->fill([
            'estado' => WhatsappConversa::PROCESSANDO,
            'payload' => $payload,
            'expira_em' => now()->addMinutes((int) config('whatsapp.conversa.ttl_processando_minutos')),
        ])->save();

        return $conversa;
    }

    /**
     * O que o job deve retomar: estado de origem e a mensagem que o disparou.
     *
     * @return array{estado: string, mensagem_resposta_id: int|null}
     */
    public function retomar(WhatsappConversa $conversa): array
    {
        $retomar = $conversa->payload['_retomar'] ?? [];

        return [
            'estado' => (string) ($retomar['estado'] ?? WhatsappConversa::OCIOSO),
            'mensagem_resposta_id' => isset($retomar['mensagem_resposta_id'])
                ? (int) $retomar['mensagem_resposta_id']
                : null,
        ];
    }

    /**
     * A proposta pendente, sem as chaves de controle interno.
     *
     * @return array<string, mixed>
     */
    public function proposta(WhatsappConversa $conversa): array
    {
        $payload = $conversa->payload ?? [];
        unset($payload['_retomar']);

        return $payload;
    }

    /**
     * Volta para ocioso e descarta o anexo pendente. Usado no cancelamento, na
     * expiração, no fim de um fluxo e no failed() dos jobs.
     */
    public function fechar(WhatsappConversa $conversa, bool $manterAnexo = false): WhatsappConversa
    {
        if (! $manterAnexo && $conversa->anexo_path !== null) {
            $this->apagarAnexo($conversa->anexo_path);
        }

        $conversa->fill([
            'estado' => WhatsappConversa::OCIOSO,
            'fluxo' => null,
            'mensagem_id' => null,
            'anexo_path' => null,
            'payload' => null,
            'tentativas' => 0,
            'expira_em' => null,
        ])->save();

        return $conversa;
    }

    /**
     * Registra mais uma resposta que não deu para interpretar. Devolve o total.
     */
    public function registrarTentativa(WhatsappConversa $conversa): int
    {
        $conversa->increment('tentativas');

        return (int) $conversa->tentativas;
    }

    public function excedeuTentativas(WhatsappConversa $conversa): bool
    {
        return $conversa->tentativas >= (int) config('whatsapp.conversa.max_tentativas');
    }

    private function apagarAnexo(string $path): void
    {
        // Só mexe no diretório de staging: paths já promovidos (refeição
        // registrada, comprovante lançado) pertencem ao lançamento.
        if (str_starts_with($path, 'whatsapp/pendentes/')) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
