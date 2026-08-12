<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Envio de mensagens pela aplicação (relatórios, perguntas e confirmações do
 * assistente). Grava a mensagem com origem 'sistema' para o ingest reconhecê-la.
 *
 * Anti-loop em três camadas, porque o dedupe por message_id sozinho tem furo —
 * se a Evolution não devolver o id, o eco do webhook não casa com nada e o bot
 * processa a própria pergunta como se fosse resposta do usuário:
 *   1. o hash do texto é registrado no cache ANTES do envio (chaveEco), e o
 *      ingest descarta o que reconhecer — funciona em qualquer ordem de chegada;
 *   2. a linha é gravada com updateOrCreate, tolerando o eco que chegou antes;
 *   3. um disjuntor por instância corta o envio se algo entrar em ciclo.
 */
class WhatsappSender
{
    /**
     * Chave de cache que marca "este texto saiu daqui". Compartilhada com o
     * WhatsappIngestService, que a consome ao reconhecer o eco.
     */
    public static function chaveEco(int $instanciaId, string $texto): string
    {
        return "wa:eco:{$instanciaId}:".sha1($texto);
    }

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

        if (! $this->dentroDoLimite($instancia)) {
            return false;
        }

        // Antes do envio: o webhook pode devolver o eco antes de sendText() retornar.
        Cache::put(
            self::chaveEco($instancia->id, $texto),
            true,
            now()->addMinutes((int) config('whatsapp.conversa.eco_ttl_minutos')),
        );

        $resp = $svc->sendText($phone, $texto);
        if (! ($resp['sucesso'] ?? false)) {
            Cache::forget(self::chaveEco($instancia->id, $texto));
            Log::error('[whatsapp:sender] falha no envio: '.($resp['erro'] ?? 'desconhecida'));

            return false;
        }

        $messageId = (string) ($resp['response']['messageId'] ?? '');
        $chat = $this->resolverChatPorPhone($instancia, $phone);

        if ($chat !== null) {
            $this->registrarMensagem($instancia, $chat, $messageId, $texto);
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

    /**
     * Disjuntor: um loop de resposta automática queimaria crédito da Anthropic e
     * da Evolution em minutos. Melhor calar a instância e deixar rastro no log.
     */
    private function dentroDoLimite(WhatsappInstancia $instancia): bool
    {
        $chave = "wa:out:{$instancia->id}";
        $teto = (int) config('whatsapp.conversa.max_envios_por_minuto');

        if (RateLimiter::tooManyAttempts($chave, $teto)) {
            Log::error("[whatsapp:sender] teto de {$teto} envios/min atingido na instância {$instancia->id}; envio suspenso (possível loop).");

            return false;
        }

        RateLimiter::hit($chave, 60);

        return true;
    }

    /**
     * Grava a mensagem enviada. updateOrCreate porque o eco do webhook pode ter
     * chegado primeiro e já criado a linha com este message_id — nesse caso só
     * marcamos a origem em vez de estourar no índice único.
     */
    private function registrarMensagem(WhatsappInstancia $instancia, WhatsappChat $chat, string $messageId, string $texto): void
    {
        $dados = [
            'chat_id' => $chat->id,
            'phone' => $chat->phone,
            'from_me' => true,
            'tipo' => 'text',
            'texto' => $texto,
            'status' => 'SENT',
            'momment' => (int) round(microtime(true) * 1000),
            'origem' => 'sistema',
        ];

        try {
            $mensagem = $messageId !== ''
                ? WhatsappMensagem::updateOrCreate(
                    ['instancia_id' => $instancia->id, 'message_id' => $messageId],
                    $dados,
                )
                : WhatsappMensagem::create([
                    ...$dados,
                    'instancia_id' => $instancia->id,
                    'message_id' => null,
                ]);
        } catch (QueryException $e) {
            // Corrida com o eco do webhook: a mensagem já está no banco, o que
            // importa (não reprocessar) já foi garantido pelo hash no cache.
            Log::warning('[whatsapp:sender] mensagem enviada já registrada pelo webhook: '.$e->getMessage());

            return;
        }

        $chat->update([
            'last_message_id' => $mensagem->message_id,
            'last_message_text' => $mensagem->texto,
            'last_message_from_me' => true,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);
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
