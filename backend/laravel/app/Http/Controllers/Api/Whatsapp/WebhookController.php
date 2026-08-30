<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Services\Whatsapp\EvolutionWebhookNormalizer;
use App\Services\Whatsapp\WhatsappIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe os eventos da Evolution API. Rota pública (fora do auth:api), mas
 * protegida por token na query string — a URL é alcançável pelo proxy do Next.
 * Sempre responde 200 depois de autenticado, para a Evolution não reenfileirar.
 */
class WebhookController extends Controller
{
    public function evolution(
        Request $request,
        EvolutionWebhookNormalizer $normalizer,
        WhatsappIngestService $ingest,
    ): JsonResponse {
        $tokenEsperado = (string) config('whatsapp.webhook.token');
        if ($tokenEsperado === '' || ! hash_equals($tokenEsperado, (string) $request->query('token'))) {
            return response()->json(['message' => 'Token inválido.'], 403);
        }

        try {
            $event = strtolower((string) $request->input('event', ''));
            $instanceName = (string) $request->input('instance', '');
            $data = $request->input('data');
            $data = is_array($data) ? $data : [];

            $instancia = $ingest->resolverInstancia($instanceName);
            if ($instancia === null) {
                return response()->json(['ok' => true, 'ignored' => 'instancia desconhecida']);
            }

            // "Apagar para todos" chega como messages.delete e/ou como um
            // messages.upsert de protocolMessage — depende da versão da
            // Evolution. Checar antes do fluxo normal: o normalizarUpsert
            // descarta protocolMessage, então o revoke se perderia ali.
            $revokes = $normalizer->normalizarRevokes($data, $event);
            // "Editar mensagem" chega como messages.edited (a Evolution emite
            // esse evento para qualquer protocolMessage, então quem separa
            // edição de exclusão é o formato, não o nome do evento) e, em
            // versões que não cortam o upsert, também por lá.
            $edicoes = $normalizer->normalizarEdicoes($data);

            if ($revokes !== []) {
                foreach ($revokes as $revoke) {
                    $ingest->marcarApagada($revoke, $instancia);
                }
            } elseif ($edicoes !== []) {
                foreach ($edicoes as $edicao) {
                    $ingest->marcarEditada($edicao, $instancia);
                }
            } elseif (in_array($event, ['messages.upsert', 'send.message'], true)) {
                $norm = $normalizer->normalizarUpsert($data, $instanceName);
                if ($norm !== null) {
                    $ingest->processarEvento($norm, $instancia);
                }
            } elseif ($event === 'messages.update') {
                $messageId = (string) ($data['keyId'] ?? $data['messageId'] ?? ($data['key']['id'] ?? ''));
                $status = $normalizer->normalizarStatus((string) ($data['status'] ?? ''));
                $ingest->atualizarStatus($instancia, $messageId, $status);
            } elseif ($event === 'connection.update') {
                // Quando o Baileys EMITE a queda, o painel fica sabendo na hora
                // em vez de esperar alguém abrir a tela. Não cobre a sessão
                // zumbi, que é exatamente o caso em que este evento não vem —
                // quem cuida disso é o job VerificarSessaoWhatsapp.
                $estado = strtolower((string) ($data['state'] ?? $data['connection'] ?? ''));
                $novo = match ($estado) {
                    'open' => 'conectado',
                    'connecting' => 'conectando',
                    'close' => 'desconectado',
                    default => null,
                };
                if ($novo !== null && $novo !== $instancia->status) {
                    Log::warning("[whatsapp:webhook] instância {$instanceName} mudou para '{$estado}'.");
                    $instancia->update(['status' => $novo]);
                }
            }
            // Demais eventos (qrcode.updated...) são ignorados.
        } catch (\Throwable $e) {
            // Nunca devolver erro à Evolution: logar e seguir.
            Log::error('[whatsapp:webhook] '.$e->getMessage(), ['exception' => $e]);
        }

        return response()->json(['ok' => true]);
    }
}
