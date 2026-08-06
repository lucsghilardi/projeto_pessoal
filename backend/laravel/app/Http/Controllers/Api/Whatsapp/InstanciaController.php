<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\EvolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestão da instância de WhatsApp do usuário (uma por usuário): criação na
 * Evolution, QR code / pareamento, status da sessão e preferências do módulo.
 */
class InstanciaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);

        return response()->json(['instancia' => $instancia]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apelido' => ['nullable', 'string', 'max:60'],
        ]);

        if ($this->instanciaDoUsuario($request) !== null) {
            return response()->json(['message' => 'Você já tem uma instância de WhatsApp configurada.'], 422);
        }

        $instanceName = 'raiz-u'.$request->user()->id.'-'.Str::lower(Str::random(6));
        $svc = new EvolutionService($instanceName);

        if (! $svc->configOk()) {
            return response()->json(['message' => 'Evolution API não configurada. Defina EVOLUTION_BASE_URL e EVOLUTION_API_KEY no .env.'], 422);
        }

        $resp = $svc->createInstance();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao criar a instância na Evolution: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        $webhook = $svc->setWebhook($this->webhookUrl());
        if (! ($webhook['sucesso'] ?? false)) {
            Log::warning('[whatsapp:instancia] webhook não configurado na criação: '.($webhook['erro'] ?? ''));
        }

        $instancia = WhatsappInstancia::create([
            'user_id' => $request->user()->id,
            'apelido' => $data['apelido'] ?? null,
            'instance_name' => $instanceName,
            'status' => 'conectando',
        ]);

        return response()->json(['instancia' => $instancia], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $data = $request->validate([
            'apelido' => ['nullable', 'string', 'max:60'],
            'gtd_ativo' => ['sometimes', 'boolean'],
            'calorias_foto_ativo' => ['sometimes', 'boolean'],
            'calorias_texto_ia' => ['sometimes', 'boolean'],
            'relatorio_diario_ativo' => ['sometimes', 'boolean'],
            'resumo_matinal_ativo' => ['sometimes', 'boolean'],
        ]);

        $instancia->update($data);

        return response()->json(['instancia' => $instancia]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        // Melhor esforço na Evolution; a linha (e chats/mensagens, em cascata)
        // sai do banco de qualquer forma.
        $svc = EvolutionService::forInstancia($instancia);
        try {
            $svc->logout();
            $svc->deleteInstance();
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:instancia] falha ao remover na Evolution: '.$e->getMessage());
        }

        $instancia->delete();

        return response()->json(['message' => 'Instância removida com sucesso.']);
    }

    public function qrcode(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->getQrcode();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao obter o QR code: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        $body = is_array($resp['response']) ? $resp['response'] : [];

        return response()->json([
            'base64' => (string) ($body['base64'] ?? ''),
            'code' => (string) ($body['code'] ?? ''),
            'pairing_code' => (string) ($body['pairingCode'] ?? ''),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->fetchDevice();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json([
                'instancia' => $instancia,
                'connected' => false,
                'session' => 'indisponivel',
                'erro' => $resp['erro'] ?? 'Evolution indisponível',
            ]);
        }

        $device = $resp['response'];
        $novoStatus = $device['connected'] ? 'conectado' : ($device['session'] === 'connecting' ? 'conectando' : 'desconectado');

        $atualizacoes = ['status' => $novoStatus];
        if (($device['phone'] ?? '') !== '') {
            $atualizacoes['phone'] = $device['phone'];
        }
        $instancia->update($atualizacoes);

        return response()->json([
            'instancia' => $instancia,
            'connected' => (bool) $device['connected'],
            'session' => (string) $device['session'],
        ]);
    }

    /**
     * Reaponta o webhook da instância para esta aplicação (útil após restaurar
     * backup ou trocar de ambiente).
     */
    public function reconfigurarWebhook(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->setWebhook($this->webhookUrl());
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao configurar o webhook: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        return response()->json(['message' => 'Webhook reconfigurado com sucesso.']);
    }

    private function instanciaDoUsuario(Request $request): ?WhatsappInstancia
    {
        return WhatsappInstancia::where('user_id', $request->user()->id)->first();
    }

    private function webhookUrl(): string
    {
        $url = (string) config('whatsapp.webhook.url');
        $token = (string) config('whatsapp.webhook.token');

        return $url.(str_contains($url, '?') ? '&' : '?').'token='.urlencode($token);
    }
}
