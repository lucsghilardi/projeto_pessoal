<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappInstancia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente da Evolution API v2 (porte enxuto do módulo WhatsApp do Nitrogym).
 * A base_url e a api_key são globais (config/whatsapp.php) — o container da
 * Evolution roda no próprio compose deste projeto.
 */
class EvolutionService
{
    private string $baseUrl;
    private string $instanceName;
    private string $apiKey;

    public function __construct(string $instanceName)
    {
        $this->baseUrl = rtrim((string) config('whatsapp.evolution.base_url'), '/');
        $this->instanceName = $instanceName;
        $this->apiKey = (string) config('whatsapp.evolution.api_key');
    }

    public static function forInstancia(WhatsappInstancia $instancia): self
    {
        return new self((string) $instancia->instance_name);
    }

    public function configOk(): bool
    {
        return $this->baseUrl !== '' && $this->instanceName !== '' && $this->apiKey !== '';
    }

    // ============================================================
    // Provisionamento (criação + QR + webhook)
    // ============================================================

    public function createInstance(): array
    {
        return $this->http('POST', '/instance/create', [
            'instanceName' => $this->instanceName,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]);
    }

    public function getQrcode(): array
    {
        return $this->http('GET', "/instance/connect/{$this->instanceName}");
    }

    /**
     * Código de pareamento (8 caracteres) em vez de QR. Exige logout antes:
     * um socket que já entrou em modo QR ignora o número.
     */
    public function getPairingCode(string $phone): array
    {
        return $this->http('GET', "/instance/connect/{$this->instanceName}", null, [
            'number' => $this->normalizarNumero($phone),
        ]);
    }

    public function setWebhook(string $url, array $events = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE']): array
    {
        return $this->http('POST', "/webhook/set/{$this->instanceName}", [
            'webhook' => [
                'enabled' => true,
                'url' => $url,
                // Evolution v2 espera 'byEvents'/'base64' — os nomes da v1
                // ('webhookByEvents'/'webhookBase64') são IGNORADOS em silêncio.
                'byEvents' => false,
                // Mídia chega em base64 no payload (data.message.base64) — usado
                // pelo diário alimentar (foto de prato). A Evolution roda com
                // DATABASE_SAVE_DATA_NEW_MESSAGE=false, então baixar depois via
                // getBase64FromMediaMessage não funcionaria.
                'base64' => true,
                'events' => $events,
            ],
        ]);
    }

    public function logout(): array
    {
        return $this->http('DELETE', "/instance/logout/{$this->instanceName}");
    }

    public function deleteInstance(): array
    {
        return $this->http('DELETE', "/instance/delete/{$this->instanceName}");
    }

    /**
     * Estado da conexão + telefone conectado (normalizado).
     */
    public function fetchDevice(): array
    {
        $resp = $this->http('GET', '/instance/fetchInstances', null, ['instanceName' => $this->instanceName]);
        if (! $resp['sucesso']) {
            return $resp;
        }

        $bruto = $resp['response'];
        $instancia = null;
        if (is_array($bruto)) {
            if (isset($bruto[0]) && is_array($bruto[0])) {
                $instancia = $bruto[0];
            } elseif (isset($bruto['instance']) && is_array($bruto['instance'])) {
                $instancia = $bruto['instance'];
            } else {
                $instancia = $bruto;
            }
        }

        $estado = (string) ($instancia['connectionStatus'] ?? $instancia['state'] ?? '');
        $ownerJid = (string) ($instancia['ownerJid'] ?? $instancia['owner'] ?? '');
        $phone = '';
        if ($ownerJid !== '') {
            $phone = preg_replace('/\D+/', '', explode('@', $ownerJid, 2)[0] ?? '') ?? '';
        }

        return [
            'sucesso' => true,
            'http_code' => $resp['http_code'],
            'response' => [
                'phone' => $phone,
                'connected' => $estado === 'open',
                'session' => $estado,
            ],
        ];
    }

    // ============================================================
    // Envio (relatórios e confirmações GTD — o painel é só leitura)
    // ============================================================

    public function sendText(string $phone, string $message): array
    {
        $resp = $this->http('POST', "/message/sendText/{$this->instanceName}", [
            'number' => $this->normalizarNumero($phone),
            'text' => $message,
        ]);

        return $this->normalizarRespostaEnvio($resp);
    }

    // ============================================================
    // Consultas auxiliares
    // ============================================================

    /** Assunto/nome de um grupo pelo JID (xxxx@g.us). null em falha. */
    public function fetchGroupSubject(string $groupJid): ?string
    {
        if ($groupJid === '') {
            return null;
        }
        $resp = $this->http('GET', "/group/findGroupInfos/{$this->instanceName}", null, ['groupJid' => $groupJid]);
        if (! ($resp['sucesso'] ?? false)) {
            return null;
        }
        $r = $resp['response'];
        if (is_array($r)) {
            $subject = $r['subject'] ?? ($r[0]['subject'] ?? null);

            return is_string($subject) && $subject !== '' ? $subject : null;
        }

        return null;
    }

    /** URL pública da foto de perfil de um contato, ou null. */
    public function fetchProfilePicture(string $phone): ?string
    {
        $resp = $this->http('POST', "/chat/fetchProfilePictureUrl/{$this->instanceName}", [
            'number' => $this->normalizarNumero($phone),
        ]);
        if (! ($resp['sucesso'] ?? false)) {
            return null;
        }
        $r = $resp['response'];
        if (is_array($r)) {
            $url = $r['profilePictureUrl'] ?? $r['url'] ?? null;

            return is_string($url) && $url !== '' ? $url : null;
        }

        return null;
    }

    // ============================================================
    // HTTP / utils
    // ============================================================

    /**
     * Só são repetidas as falhas de conexão anteriores ao envio (DNS/abertura
     * de conexão) — repetir não duplica envios.
     */
    private const MAX_TENTATIVAS = 3;

    private function http(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $method = strtoupper($method);

        for ($tentativa = 1; ; $tentativa++) {
            try {
                $req = Http::withHeaders(['apikey' => $this->apiKey])
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout(60);

                if ($method === 'GET') {
                    $resp = $req->get($url, $query);
                } elseif ($method === 'DELETE') {
                    $resp = $req->delete($url, $body ?? []);
                } else {
                    $resp = $req->post($url, $body ?? []);
                }
            } catch (\Throwable $e) {
                if ($tentativa < self::MAX_TENTATIVAS && $this->ehFalhaDeConexaoRepetivel($e)) {
                    Log::warning("[whatsapp:evolution] tentativa {$tentativa}/".self::MAX_TENTATIVAS." falhou ({$e->getMessage()}); repetindo");
                    usleep(300_000 * (2 ** ($tentativa - 1)));

                    continue;
                }
                Log::error('[whatsapp:evolution] falha de comunicacao: '.$e->getMessage());

                return ['sucesso' => false, 'http_code' => 0, 'erro' => 'Falha na comunicação com a Evolution API: '.$e->getMessage()];
            }

            $httpCode = $resp->status();
            $decoded = $resp->json();

            if ($httpCode < 200 || $httpCode >= 300) {
                $erroMsg = 'Resposta inválida da Evolution API. HTTP '.$httpCode;
                if (is_array($decoded) && isset($decoded['message'])) {
                    $erroMsg .= ' - '.(is_array($decoded['message']) ? json_encode($decoded['message']) : (string) $decoded['message']);
                }

                return ['sucesso' => false, 'http_code' => $httpCode, 'erro' => $erroMsg, 'response' => $decoded ?? $resp->body()];
            }

            return ['sucesso' => true, 'http_code' => $httpCode, 'response' => $decoded ?? $resp->body()];
        }
    }

    /**
     * Falhas ANTES de qualquer byte sair (DNS, TCP, TLS) são seguras de
     * repetir. Timeout de transferência não é (a mensagem pode ter saído).
     */
    private function ehFalhaDeConexaoRepetivel(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'operation timed out')) {
            return false;
        }

        foreach ([
            'could not resolve host',
            'could not resolve proxy',
            'resolving timed out',
            'failed to connect',
            "couldn't connect to server",
            'connection refused',
            'connection timed out',
            'ssl connect error',
        ] as $agulha) {
            if (str_contains($msg, $agulha)) {
                return true;
            }
        }

        return false;
    }

    private function normalizarRespostaEnvio(array $resp): array
    {
        if (! ($resp['sucesso'] ?? false)) {
            return $resp;
        }
        $body = is_array($resp['response'] ?? null) ? $resp['response'] : [];

        $id = '';
        if (isset($body['key']['id'])) {
            $id = (string) $body['key']['id'];
        } elseif (isset($body['messageId'])) {
            $id = (string) $body['messageId'];
        } elseif (isset($body['id'])) {
            $id = (string) $body['id'];
        }

        if ($id !== '') {
            $body['messageId'] = $body['messageId'] ?? $id;
        }

        $resp['response'] = $body;

        return $resp;
    }

    private function normalizarNumero(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }
}
