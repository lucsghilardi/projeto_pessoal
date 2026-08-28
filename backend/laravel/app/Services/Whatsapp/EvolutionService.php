<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappInstancia;
use Illuminate\Support\Facades\Cache;
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

    /**
     * Eventos que a Evolution entrega neste webhook. MESSAGES_DELETE alimenta o
     * aviso de mensagem apagada e MESSAGES_EDITED o de mensagem editada.
     *
     * Quem guarda a assinatura é a Evolution, por instância: acrescentar um
     * evento aqui não muda nada em quem já foi criado antes. Só passa a valer
     * depois de reassinar — `php artisan whatsapp:reassinar-webhook`, ou o
     * POST /api/whatsapp/instancia/webhook. Confira com /webhook/find.
     */
    public const EVENTOS = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'MESSAGES_DELETE', 'MESSAGES_EDITED', 'SEND_MESSAGE'];

    /**
     * URL que a Evolution chama, com o token na query string — o
     * WebhookController confere esse token antes de olhar o payload.
     */
    public static function webhookUrlConfigurada(): string
    {
        $url = (string) config('whatsapp.webhook.url');
        $token = (string) config('whatsapp.webhook.token');

        return $url.(str_contains($url, '?') ? '&' : '?').'token='.urlencode($token);
    }

    /**
     * @param  list<string>|null  $events  null usa a lista padrão (self::EVENTOS)
     */
    public function setWebhook(string $url, ?array $events = null): array
    {
        $events ??= self::EVENTOS;

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

        // `connectionStatus` é um campo de banco que a Evolution só atualiza
        // quando o Baileys emite connection.update. Se o socket morre sem
        // emitir — foi o caso em 24/08/2026 —, ele fica preso em 'open' para
        // sempre: o painel mostra "conectado" enquanto a instância não recebe
        // webhook nem consegue enviar. Confirmamos com uma sonda antes de
        // repassar o 'open' adiante.
        $conectado = $estado === 'open';
        if ($conectado && $this->socketMorto()) {
            Log::warning("[whatsapp:evolution] instância {$this->instanceName} marcada como 'open' na Evolution, mas o socket não responde (sessão zumbi); é preciso reparear.");
            $conectado = false;
            $estado = 'zumbi';
        }

        return [
            'sucesso' => true,
            'http_code' => $resp['http_code'],
            'response' => [
                'phone' => $phone,
                'connected' => $conectado,
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
                $motivo = $this->extrairMensagemErro($decoded);
                if ($motivo !== '') {
                    $erroMsg .= ' - '.$motivo;
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

    /**
     * O motivo real de um erro vem em três formatos diferentes na Evolution v2,
     * e nenhum deles é o `message` da raiz que a v1 usava:
     *   - validação:  {"status":400,"response":{"message":["..."]}}
     *   - Baileys:    {"isBoom":true,"output":{"payload":{"message":"Connection Closed"}}}
     *   - simples:    {"message":"..."}
     * Sem varrer os três, o log registra só "HTTP 400" e esconde a causa — foi
     * o que manteve a sessão morta invisível por quatro dias em 08/2026.
     */
    private function extrairMensagemErro(mixed $decoded): string
    {
        if (! is_array($decoded)) {
            return '';
        }

        $candidatos = [
            $decoded['message'] ?? null,
            $decoded['response']['message'] ?? null,
            $decoded['output']['payload']['message'] ?? null,
            $decoded['error'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && $candidato !== '') {
                return $candidato;
            }
            if (! is_array($candidato) || $candidato === []) {
                continue;
            }

            // A Evolution manda a mensagem de validação como lista. Juntar os
            // itens mantém o log legível; json_encode escaparia os acentos
            // ("número") e o motivo viraria ruído.
            $escalares = array_filter($candidato, static fn ($item) => is_scalar($item));
            if (count($escalares) === count($candidato)) {
                return implode('; ', array_map(static fn ($item) => (string) $item, $escalares));
            }

            return (string) json_encode($candidato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '';
    }

    /**
     * Sonda se o socket do WhatsApp responde de fato.
     *
     * A consulta usa um número fora da agenda de propósito: para um contato
     * conhecido a Evolution responde do cache local e a sonda nunca tocaria o
     * socket. Com número desconhecido o Baileys precisa perguntar ao servidor,
     * e é aí que uma sessão zumbi se denuncia com "Connection Closed".
     *
     * Só devolve true diante da falha explícita. Timeout, DNS ou 5xx mantêm o
     * status otimista — um soluço de rede não deve marcar a instância como
     * desconectada.
     */
    public function socketMorto(): bool
    {
        $numero = (string) config('whatsapp.evolution.numero_sonda');
        if ($numero === '') {
            return false;
        }

        $ttl = (int) config('whatsapp.evolution.sonda_ttl_segundos');
        $chave = "wa:sonda:{$this->instanceName}";

        $sondar = function () use ($numero): bool {
            $resp = $this->http('POST', "/chat/whatsappNumbers/{$this->instanceName}", [
                'numbers' => [$this->normalizarNumero($numero)],
            ]);

            if ($resp['sucesso'] ?? false) {
                return false;
            }

            // 4xx com "Connection Closed"/428 é o socket caído. Qualquer outra
            // falha (0 = rede, 5xx = Evolution em apuros) não prova nada.
            $codigo = (int) ($resp['http_code'] ?? 0);
            if ($codigo < 400 || $codigo >= 500) {
                return false;
            }

            $motivo = strtolower($this->extrairMensagemErro($resp['response'] ?? null));
            $statusBaileys = (int) ($resp['response']['output']['payload']['statusCode'] ?? 0);

            return str_contains($motivo, 'connection closed') || $statusBaileys === 428;
        };

        return $ttl > 0
            ? (bool) Cache::remember($chave, $ttl, $sondar)
            : $sondar();
    }
}
