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
     * Reconhece "apagar para todos". O mesmo revoke chega de duas formas, e qual
     * delas depende da versão da Evolution — por isso o evento entra como
     * parâmetro em vez de ser adivinhado pelo formato do payload:
     *
     *   1. messages.delete — o data É a key da mensagem apagada (ou vem em
     *      data.key, ou como lista em data.keys, que é o formato cru do Baileys
     *      quando várias somem de uma vez);
     *   2. messages.upsert com messageType protocolMessage — a key externa é a
     *      do stanza de revoke e a interna (message.protocolMessage.key) aponta
     *      a mensagem que sumiu.
     *
     * Devolve lista vazia quando não é revoke — inclusive para os outros
     * protocolMessage (EPHEMERAL_SETTING, APP_STATE_SYNC...), que não interessam.
     *
     * @return list<array{messageId: string, remoteJid: string, porMim: bool}>
     */
    public function normalizarRevokes(array $data, string $event): array
    {
        $keys = $event === 'messages.delete'
            ? $this->keysDoDelete($data)
            : $this->keysDoProtocolMessage($data);

        $revokes = [];
        foreach ($keys as $key) {
            $messageId = (string) ($key['id'] ?? '');
            if ($messageId === '') {
                continue;
            }

            $revokes[] = [
                'messageId' => $messageId,
                'remoteJid' => (string) ($key['remoteJid'] ?? ''),
                'porMim' => (bool) ($key['fromMe'] ?? false),
            ];
        }

        return $revokes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keysDoDelete(array $data): array
    {
        if (is_array($data['keys'] ?? null)) {
            return array_values(array_filter($data['keys'], 'is_array'));
        }
        if (is_array($data['key'] ?? null)) {
            return [$data['key']];
        }

        return isset($data['id']) ? [$data] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function keysDoProtocolMessage(array $data): array
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $protocolo = is_array($message['protocolMessage'] ?? null) ? $message['protocolMessage'] : [];
        if ($protocolo === []) {
            return [];
        }

        // REVOKE é 0 no enum do Baileys; a Evolution ora manda o nome, ora o
        // número. Sem type declarado não dá para afirmar que é revoke.
        $tipo = $protocolo['type'] ?? null;
        if (strtoupper((string) $tipo) !== 'REVOKE' && $tipo !== 0 && $tipo !== '0') {
            return [];
        }

        $keyAlvo = is_array($protocolo['key'] ?? null) ? $protocolo['key'] : [];
        if ($keyAlvo === []) {
            return [];
        }

        // A key interna costuma vir só com o id; o resto completa pela externa.
        // fromMe aqui é de quem ESCREVEU a mensagem apagada, não de quem emitiu
        // o stanza de revoke — é esse o "quem" que interessa.
        $keyExterna = is_array($data['key'] ?? null) ? $data['key'] : [];

        return [$keyAlvo + $keyExterna];
    }

    /**
     * Reconhece "editar mensagem" e devolve o texto novo junto da key da
     * mensagem original.
     *
     * Ao contrário do revoke, aqui o evento não precisa entrar como parâmetro:
     * o edit se reconhece sozinho pelo formato, porque só ele carrega um
     * editedMessage. E precisa ser assim — a Evolution dispara MESSAGES_EDITED
     * para qualquer protocolMessage, revoke inclusive, então olhar só o nome do
     * evento marcaria exclusão como edição.
     *
     * Os dois formatos conhecidos:
     *
     *   1. messages.edited — o data JÁ É o protocolMessage (key aponta a
     *      mensagem original, editedMessage traz o conteúdo novo);
     *   2. messages.upsert com protocolMessage — o mesmo conteúdo aninhado em
     *      data.message.protocolMessage ou no embrulho do Baileys, em
     *      data.message.editedMessage.message.protocolMessage.
     *
     * Na v2.3.7 só o primeiro chega: a Evolution corta o upsert assim que vê um
     * protocolMessage, antes de emitir MESSAGES_UPSERT. O segundo fica de pé
     * para as versões que entregam o edit por lá.
     *
     * @return list<array{messageId: string, remoteJid: string, porMim: bool, texto: string}>
     */
    public function normalizarEdicoes(array $data): array
    {
        $protocolo = is_array($data['editedMessage'] ?? null)
            ? $data
            : $this->protocolMessageDoUpsert($data);

        $editada = is_array($protocolo['editedMessage'] ?? null) ? $protocolo['editedMessage'] : [];
        if ($editada === []) {
            return [];
        }

        // A key interna costuma vir só com o id; o resto completa pela externa
        // (que existe no caminho do upsert). fromMe é de quem ESCREVEU a
        // mensagem editada — é esse o "quem" que interessa.
        $keyExterna = is_array($data['key'] ?? null) ? $data['key'] : [];
        $key = (is_array($protocolo['key'] ?? null) ? $protocolo['key'] : []) + $keyExterna;

        $messageId = (string) ($key['id'] ?? '');
        // Texto novo vazio seria um edit sem conteúdo: nada a gravar nem a
        // mostrar. O resumo é o mesmo das citações — legenda de mídia inclusa.
        $texto = trim($this->resumoDaMensagem($editada));
        if ($messageId === '' || $texto === '') {
            return [];
        }

        return [[
            'messageId' => $messageId,
            'remoteJid' => (string) ($key['remoteJid'] ?? ''),
            'porMim' => (bool) ($key['fromMe'] ?? false),
            'texto' => $texto,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function protocolMessageDoUpsert(array $data): array
    {
        $message = is_array($data['message'] ?? null) ? $data['message'] : [];

        $direto = is_array($message['protocolMessage'] ?? null) ? $message['protocolMessage'] : [];
        if ($direto !== []) {
            return $direto;
        }

        $embrulhado = $message['editedMessage']['message']['protocolMessage'] ?? null;

        return is_array($embrulhado) ? $embrulhado : [];
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
                'texto' => $this->resumoDaMensagem($quoted),
            ];
        }

        return null;
    }

    /**
     * Resume um node de mensagem crua do Baileys em uma linha de texto —
     * serve tanto para a citação quanto para o conteúdo novo de uma edição.
     */
    private function resumoDaMensagem(array $quoted): string
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
