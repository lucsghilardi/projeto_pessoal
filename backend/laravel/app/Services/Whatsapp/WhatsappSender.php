<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use Illuminate\Support\Facades\Log;

/**
 * Envio de mensagens pela aplicação (relatórios e confirmações GTD). Grava a
 * mensagem com origem 'sistema' imediatamente: o dedupe por message_id no
 * ingest impede que o webhook a reprocesse (e dispare GTD em loop).
 */
class WhatsappSender
{
    /**
     * Envia texto para um telefone. Retorna true em sucesso.
     */
    public function enviarTexto(WhatsappInstancia $instancia, string $phone, string $texto): bool
    {
        $svc = EvolutionService::forInstancia($instancia);
        if (! $svc->configOk()) {
            Log::warning('[whatsapp:sender] Evolution não configurada; envio ignorado.');

            return false;
        }

        $resp = $svc->sendText($phone, $texto);
        if (! ($resp['sucesso'] ?? false)) {
            Log::error('[whatsapp:sender] falha no envio: '.($resp['erro'] ?? 'desconhecida'));

            return false;
        }

        $messageId = (string) ($resp['response']['messageId'] ?? '');
        $chat = $this->resolverChatPorPhone($instancia, $phone);

        if ($chat !== null) {
            $mensagem = WhatsappMensagem::create([
                'chat_id' => $chat->id,
                'instancia_id' => $instancia->id,
                'message_id' => $messageId !== '' ? $messageId : null,
                'phone' => $chat->phone,
                'from_me' => true,
                'tipo' => 'text',
                'texto' => $texto,
                'status' => 'SENT',
                'momment' => (int) round(microtime(true) * 1000),
                'origem' => 'sistema',
            ]);

            $chat->update([
                'last_message_id' => $mensagem->message_id,
                'last_message_text' => $mensagem->texto,
                'last_message_from_me' => true,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
        }

        return true;
    }

    /**
     * Envia texto para o próprio número conectado (relatórios, confirmações).
     */
    public function enviarParaMim(WhatsappInstancia $instancia, string $texto): bool
    {
        $phone = (string) $instancia->phone;
        if ($phone === '') {
            Log::warning('[whatsapp:sender] instância sem telefone conectado; envio ignorado.');

            return false;
        }

        return $this->enviarTexto($instancia, $phone, $texto);
    }

    private function resolverChatPorPhone(WhatsappInstancia $instancia, string $phone): ?WhatsappChat
    {
        $digitos = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digitos === '') {
            return null;
        }

        return WhatsappChat::firstOrCreate(
            ['instancia_id' => $instancia->id, 'chave' => $digitos],
            ['phone' => $digitos, 'is_group' => false],
        );
    }
}
