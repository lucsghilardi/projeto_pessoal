<?php

namespace App\Services\Whatsapp;

use App\Jobs\ProcessarInboxGtd;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use Illuminate\Support\Facades\Log;

/**
 * Grava no banco os eventos normalizados do webhook da Evolution. Porte enxuto
 * do ingest do Nitrogym: sem multi-grupo, sem campanhas, sem atendente virtual.
 * Extra deste projeto: mensagens enviadas para si mesmo viram tarefas (GTD).
 */
class WhatsappIngestService
{
    public function __construct(private EvolutionWebhookNormalizer $normalizer)
    {
    }

    public function resolverInstancia(string $instanceName): ?WhatsappInstancia
    {
        if ($instanceName === '') {
            return null;
        }

        return WhatsappInstancia::where('instance_name', $instanceName)->first();
    }

    /**
     * Processa um messages.upsert / send.message já normalizado.
     */
    public function processarEvento(array $norm, WhatsappInstancia $instancia): void
    {
        // Reações não geram mensagem nova.
        if (! empty($norm['reaction'])) {
            return;
        }

        $messageId = (string) ($norm['messageId'] ?? '');

        // Dedupe: send.message + messages.upsert chegam para a mesma mensagem,
        // e mensagens enviadas pela aplicação já foram gravadas pelo sender.
        if ($messageId !== '') {
            $jaExiste = WhatsappMensagem::where('instancia_id', $instancia->id)
                ->where('message_id', $messageId)
                ->exists();
            if ($jaExiste) {
                return;
            }
        }

        $chat = $this->resolverChat($norm, $instancia);
        if ($chat === null) {
            return;
        }

        $conteudo = $this->normalizer->extrairConteudo($norm);
        $fromMe = (bool) ($norm['fromMe'] ?? false);
        $momment = (int) ($norm['momment'] ?? 0);

        $mensagem = WhatsappMensagem::create([
            'chat_id' => $chat->id,
            'instancia_id' => $instancia->id,
            'message_id' => $messageId !== '' ? $messageId : null,
            'phone' => $chat->phone,
            'from_me' => $fromMe,
            'sender_name' => (string) ($norm['senderName'] ?? ''),
            'tipo' => $conteudo['tipo'],
            'texto' => $conteudo['texto'],
            'caption' => $conteudo['caption'],
            'media_url' => $conteudo['media_url'],
            'media_mime' => $conteudo['media_mime'],
            'media_filename' => $conteudo['media_filename'],
            'status' => (string) ($norm['status'] ?? ''),
            'momment' => $momment,
            'quoted_message_id' => $norm['quoted']['messageId'] ?? null,
            'quoted_texto' => $norm['quoted']['texto'] ?? null,
            'raw_payload' => $norm,
        ]);

        $this->atualizarResumoDoChat($chat, $mensagem);

        $this->detectarInboxGtd($chat, $mensagem, $instancia);
    }

    /**
     * Atualiza o status de entrega de uma mensagem já gravada.
     */
    public function atualizarStatus(WhatsappInstancia $instancia, string $messageId, string $status): void
    {
        if ($messageId === '' || $status === '') {
            return;
        }

        WhatsappMensagem::where('instancia_id', $instancia->id)
            ->where('message_id', $messageId)
            ->update(['status' => $status]);
    }

    private function resolverChat(array $norm, WhatsappInstancia $instancia): ?WhatsappChat
    {
        $phone = (string) ($norm['phone'] ?? '');
        $chatLid = (string) ($norm['chatLid'] ?? '');
        $isGroup = (bool) ($norm['isGroup'] ?? false);

        $chave = $phone !== '' ? $phone : $chatLid;
        if ($chave === '') {
            return null;
        }

        $chat = WhatsappChat::where('instancia_id', $instancia->id)
            ->where('chave', $chave)
            ->first();

        $fromMe = (bool) ($norm['fromMe'] ?? false);
        $senderName = (string) ($norm['senderName'] ?? '');

        if ($chat === null) {
            $chatName = null;
            if ($isGroup) {
                // Nome do grupo não vem no webhook; melhor esforço via API.
                try {
                    $chatName = EvolutionService::forInstancia($instancia)->fetchGroupSubject($chatLid);
                } catch (\Throwable $e) {
                    Log::warning('[whatsapp:ingest] falha ao buscar nome do grupo: '.$e->getMessage());
                }
            }

            $chat = WhatsappChat::create([
                'instancia_id' => $instancia->id,
                'chave' => $chave,
                'phone' => $phone !== '' ? $phone : null,
                'chat_lid' => $chatLid !== '' ? $chatLid : null,
                'chat_name' => $chatName,
                'sender_name' => (! $fromMe && ! $isGroup && $senderName !== '') ? $senderName : null,
                'is_group' => $isGroup,
            ]);

            return $chat;
        }

        // Melhora dados do chat com o que o evento trouxer.
        $atualizacoes = [];
        if ($chat->phone === null && $phone !== '') {
            $atualizacoes['phone'] = $phone;
        }
        if ($chat->chat_lid === null && $chatLid !== '') {
            $atualizacoes['chat_lid'] = $chatLid;
        }
        if (! $fromMe && ! $isGroup && $senderName !== '' && $chat->sender_name !== $senderName) {
            $atualizacoes['sender_name'] = $senderName;
        }
        if ($atualizacoes !== []) {
            $chat->update($atualizacoes);
        }

        return $chat;
    }

    private function atualizarResumoDoChat(WhatsappChat $chat, WhatsappMensagem $mensagem): void
    {
        $momento = $mensagem->momment > 0
            ? now()->setTimestamp(intdiv($mensagem->momment, 1000))
            : now();

        $dados = [
            'last_message_id' => $mensagem->message_id,
            'last_message_text' => $mensagem->texto,
            'last_message_from_me' => $mensagem->from_me,
            'last_message_at' => $momento,
        ];

        if ($mensagem->from_me) {
            // Respondi: zera o contador de não lidas.
            $dados['unread_count'] = 0;
        } else {
            $dados['last_inbound_at'] = $momento;
            $dados['unread_count'] = $chat->unread_count + 1;
        }

        $chat->update($dados);
    }

    /**
     * Inbox GTD: mensagem de texto enviada para si mesmo vira tarefa no kanban.
     * Mensagens da aplicação (origem 'sistema') nunca chegam aqui — o dedupe
     * por message_id as filtra antes; o prefixo ✅ é o cinto extra de segurança
     * contra loop de confirmações.
     */
    private function detectarInboxGtd(WhatsappChat $chat, WhatsappMensagem $mensagem, WhatsappInstancia $instancia): void
    {
        if (! $instancia->gtd_ativo || ! $mensagem->from_me || $chat->is_group) {
            return;
        }

        $ehChatComigo = $chat->phone !== null
            && $instancia->phone !== null
            && $chat->phone === $instancia->phone;

        if (! $ehChatComigo) {
            return;
        }

        $texto = trim((string) $mensagem->texto);
        if ($mensagem->tipo !== 'text' || $texto === '' || str_starts_with($texto, '✅')) {
            return;
        }

        ProcessarInboxGtd::dispatch($mensagem->id);
    }
}
