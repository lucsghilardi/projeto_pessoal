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

            if (in_array($event, ['messages.upsert', 'send.message'], true)) {
                $norm = $normalizer->normalizarUpsert($data, $instanceName);
                if ($norm !== null) {
                    $ingest->processarEvento($norm, $instancia);
                }
            } elseif ($event === 'messages.update') {
                $messageId = (string) ($data['keyId'] ?? $data['messageId'] ?? ($data['key']['id'] ?? ''));
                $status = $normalizer->normalizarStatus((string) ($data['status'] ?? ''));
                $ingest->atualizarStatus($instancia, $messageId, $status);
            }
            // Demais eventos (qrcode.updated, connection.update...) são ignorados.
        } catch (\Throwable $e) {
            // Nunca devolver erro à Evolution: logar e seguir.
            Log::error('[whatsapp:webhook] '.$e->getMessage(), ['exception' => $e]);
        }

        return response()->json(['ok' => true]);
    }
}
