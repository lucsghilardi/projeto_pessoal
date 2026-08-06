<?php

namespace App\Services\Whatsapp;

/**
 * Converte os payloads da Evolution (Baileys) para um formato interno simples
 * consumido pelo WhatsappIngestService. Portado do módulo WhatsApp do Nitrogym.
 */
class EvolutionWebhookNormalizer
{
    /**
     * Normaliza um evento messages.upsert / send.message. Retorna null quando o
     * payload não deve ser processado (ex: protocolMessage).
     */
    public function normalizarUpsert(array $data, string $instanceName): ?array
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $remoteJid = (string) ($key['remoteJid'] ?? '');
        if ($remoteJid === '') {
            return null;
        }
        $isGroup = str_contains($remoteJid, '@g.us');

        $remoteJidAlt = (string) ($key['remoteJidAlt'] ?? '');
        $ehLidReal = str_contains($remoteJid, '@lid');

        $chatLid = '';
        $phone = '';
        if ($isGroup) {
            $chatLid = $remoteJid;
        } elseif ($ehLidReal) {
            $chatLid = $remoteJid;
            if ($remoteJidAlt !== '') {
                $phone = preg_replace('/\D+/', '', explode('@', $remoteJidAlt, 2)[0] ?? '') ?? '';
            }
        } else {
            $phone = preg_replace('/\D+/', '', explode('@', $remoteJid, 2)[0] ?? '') ?? '';
        }

        $messageId = (string) ($key['id'] ?? '');
        $fromMe = (bool) ($key['fromMe'] ?? false);
        $pushName = (string) ($data['pushName'] ?? '');
        $msTs = (int) ($data['messageTimestamp'] ?? 0);
        $momment = $msTs > 0 ? $msTs * 1000 : (int) round(microtime(true) * 1000);

        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $messageType = (string) ($data['messageType'] ?? '');

        $normalizado = [
            'phone' => $phone,
            'messageId' => $messageId,
            'fromMe' => $fromMe,
            'chatLid' => $chatLid,
            'senderName' => $pushName,
            'isGroup' => $isGroup,
            'instanceName' => $instanceName,
            'momment' => $momment,
            'status' => $fromMe ? 'SENT' : 'RECEIVED',
        ];

        // Reação (emoji): não gera mensagem nova — o ingest ignora por ora.
        if (isset($message['reactionMessage'])) {
            $normalizado['reaction'] = true;

            return $normalizado;
        }

        $ctx = $this->extrairContextoCitado($message);
        if ($ctx !== null) {
            $normalizado['quoted'] = $ctx;
        }

        if (isset($message['conversation'])) {
            $normalizado['text'] = ['message' => (string) $message['conversation']];
        } elseif (isset($message['extendedTextMessage']['text'])) {
            $normalizado['text'] = ['message' => (string) $message['extendedTextMessage']['text']];
        } elseif (isset($message['imageMessage'])) {
            $img = (array) $message['imageMessage'];
            $normalizado['image'] = [
                'imageUrl' => (string) ($img['url'] ?? ''),
                'caption' => (string) ($img['caption'] ?? ''),
                'mimeType' => (string) ($img['mimetype'] ?? $img['mime_type'] ?? 'image/jpeg'),
                // Presente quando o webhook está com webhookBase64 habilitado.
                // O ingest usa para salvar a foto e NUNCA persiste no banco.
                'base64' => (string) ($message['base64'] ?? ''),
            ];
        } elseif (isset($message['audioMessage'])) {
            $aud = (array) $message['audioMessage'];
            $normalizado['audio'] = [
                'audioUrl' => (string) ($aud['url'] ?? ''),
                'mimeType' => (string) ($aud['mimetype'] ?? $aud['mime_type'] ?? 'audio/ogg'),
            ];
        } elseif (isset($message['videoMessage'])) {
            $vid = (array) $message['videoMessage'];
            $normalizado['video'] = [
                'videoUrl' => (string) ($vid['url'] ?? ''),
                'caption' => (string) ($vid['caption'] ?? ''),
                'mimeType' => (string) ($vid['mimetype'] ?? $vid['mime_type'] ?? 'video/mp4'),
            ];
        } elseif (isset($message['documentMessage'])) {
            $doc = (array) $message['documentMessage'];
            $normalizado['document'] = [
                'documentUrl' => (string) ($doc['url'] ?? ''),
                'fileName' => (string) ($doc['fileName'] ?? $doc['filename'] ?? 'documento'),
                'mimeType' => (string) ($doc['mimetype'] ?? $doc['mime_type'] ?? 'application/octet-stream'),
                'caption' => (string) ($doc['caption'] ?? ''),
            ];
        } elseif (isset($message['stickerMessage'])) {
            $stk = (array) $message['stickerMessage'];
            $normalizado['sticker'] = [
                'stickerUrl' => (string) ($stk['url'] ?? ''),
                'mimeType' => (string) ($stk['mimetype'] ?? $stk['mime_type'] ?? 'image/webp'),
            ];
        } elseif (isset($message['locationMessage'])) {
            $normalizado['location'] = $message['locationMessage'];
        } elseif (isset($message['contactMessage']) || isset($message['contactsArrayMessage'])) {
            $normalizado['contact'] = $message['contactMessage'] ?? $message['contactsArrayMessage'];
        } else {
            if ($messageType === 'protocolMessage' || $messageType === '') {
                return null;
            }
        }

        return $normalizado;
    }

    /**
     * Procura um contextInfo com stanzaId (mensagem citada) em qualquer node do
     * message e devolve { messageId, texto } resumindo a citação.
     */
    private function extrairContextoCitado(array $message): ?array
    {
        foreach ($message as $node) {
            if (! is_array($node) || ! isset($node['contextInfo']) || ! is_array($node['contextInfo'])) {
                continue;
            }
            $ctx = $node['contextInfo'];
            $stanzaId = (string) ($ctx['stanzaId'] ?? '');
            if ($stanzaId === '') {
                continue;
            }
            $quoted = is_array($ctx['quotedMessage'] ?? null) ? $ctx['quotedMessage'] : [];

            return [
                'messageId' => $stanzaId,
                'texto' => $this->resumoMensagemCitada($quoted),
            ];
        }

        return null;
    }

    private function resumoMensagemCitada(array $quoted): string
    {
        if (isset($quoted['conversation'])) {
            return (string) $quoted['conversation'];
        }
        if (isset($quoted['extendedTextMessage']['text'])) {
            return (string) $quoted['extendedTextMessage']['text'];
        }
        if (isset($quoted['imageMessage'])) {
            return trim('[Imagem] '.(string) ($quoted['imageMessage']['caption'] ?? ''));
        }
        if (isset($quoted['videoMessage'])) {
            return trim('[Vídeo] '.(string) ($quoted['videoMessage']['caption'] ?? ''));
        }
        if (isset($quoted['audioMessage'])) {
            return '[Áudio]';
        }
        if (isset($quoted['documentMessage'])) {
            return '[Documento] '.(string) ($quoted['documentMessage']['fileName'] ?? '');
        }
        if (isset($quoted['stickerMessage'])) {
            return '[Figurinha]';
        }
        if (isset($quoted['locationMessage'])) {
            return '[Localização]';
        }
        if (isset($quoted['contactMessage']) || isset($quoted['contactsArrayMessage'])) {
            return '[Contato]';
        }

        return '';
    }

    /**
     * Evolution emite PENDING|SERVER_ACK|DELIVERY_ACK|READ|PLAYED.
     */
    public function normalizarStatus(string $statusBruto): string
    {
        $map = [
            'PENDING' => 'PENDING',
            'SERVER_ACK' => 'SENT',
            'DELIVERY_ACK' => 'DELIVERED',
            'READ' => 'READ',
            'PLAYED' => 'READ',
            'ERROR' => 'FAILED',
        ];
        $upper = strtoupper($statusBruto);

        return $map[$upper] ?? $upper;
    }

    /**
     * Extrai tipo/texto/mídia de um payload normalizado.
     */
    public function extrairConteudo(array $payload): array
    {
        $vazio = [
            'tipo' => 'text', 'texto' => '', 'media_url' => null,
            'media_mime' => null, 'media_filename' => null, 'caption' => null,
        ];

        if (isset($payload['text']['message'])) {
            return array_merge($vazio, ['tipo' => 'text', 'texto' => (string) $payload['text']['message']]);
        }
        if (isset($payload['image'])) {
            $img = (array) $payload['image'];
            $cap = trim((string) ($img['caption'] ?? ''));

            return [
                'tipo' => 'image',
                'texto' => $cap !== '' ? '[Imagem] '.$cap : '[Imagem]',
                'media_url' => ((string) ($img['imageUrl'] ?? '')) ?: null,
                'media_mime' => ((string) ($img['mimeType'] ?? 'image/jpeg')) ?: null,
                'media_filename' => null,
                'caption' => $cap !== '' ? $cap : null,
            ];
        }
        if (isset($payload['audio'])) {
            $aud = (array) $payload['audio'];

            return [
                'tipo' => 'audio', 'texto' => '[Áudio]',
                'media_url' => ((string) ($aud['audioUrl'] ?? '')) ?: null,
                'media_mime' => ((string) ($aud['mimeType'] ?? 'audio/ogg')) ?: null,
                'media_filename' => null, 'caption' => null,
            ];
        }
        if (isset($payload['video'])) {
            $vid = (array) $payload['video'];
            $cap = trim((string) ($vid['caption'] ?? ''));

            return [
                'tipo' => 'video',
                'texto' => $cap !== '' ? '[Vídeo] '.$cap : '[Vídeo]',
                'media_url' => ((string) ($vid['videoUrl'] ?? '')) ?: null,
                'media_mime' => ((string) ($vid['mimeType'] ?? 'video/mp4')) ?: null,
                'media_filename' => null,
                'caption' => $cap !== '' ? $cap : null,
            ];
        }
        if (isset($payload['document'])) {
            $doc = (array) $payload['document'];
            $nome = trim((string) ($doc['fileName'] ?? 'documento'));
            $cap = trim((string) ($doc['caption'] ?? ''));

            return [
                'tipo' => 'document',
                'texto' => '[Documento] '.$nome,
                'media_url' => ((string) ($doc['documentUrl'] ?? '')) ?: null,
                'media_mime' => ((string) ($doc['mimeType'] ?? 'application/octet-stream')) ?: null,
                'media_filename' => $nome,
                'caption' => $cap !== '' ? $cap : null,
            ];
        }
        if (isset($payload['sticker'])) {
            $stk = (array) $payload['sticker'];

            return [
                'tipo' => 'sticker', 'texto' => '[Figurinha]',
                'media_url' => ((string) ($stk['stickerUrl'] ?? '')) ?: null,
                'media_mime' => ((string) ($stk['mimeType'] ?? 'image/webp')) ?: null,
                'media_filename' => null, 'caption' => null,
            ];
        }
        if (isset($payload['location'])) {
            return array_merge($vazio, ['tipo' => 'location', 'texto' => '[Localização]']);
        }
        if (isset($payload['contact'])) {
            return array_merge($vazio, ['tipo' => 'contact', 'texto' => '[Contato]']);
        }

        return $vazio;
    }
}
