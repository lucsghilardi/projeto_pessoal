<?php

namespace App\Services\Whatsapp;

use App\Jobs\AnalisarAnexoWhatsapp;
use App\Jobs\AvisarMensagemApagada;
use App\Jobs\AvisarMensagemEditada;
use App\Jobs\ProcessarMensagemPessoal;
use App\Models\WhatsappChat;
use App\Models\WhatsappConversa;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Grava no banco os eventos normalizados do webhook da Evolution. Porte enxuto
 * do ingest do Nitrogym: sem multi-grupo, sem campanhas, sem atendente virtual.
 *
 * Extra deste projeto: o chat consigo mesmo é um assistente — texto vira
 * proposta de tarefa ou refeição, foto vira refeição ou comprovante, e nada é
 * gravado antes de você confirmar. Este serviço só decide o próximo passo; a
 * IA e a gravação ficam nos jobs.
 */
class WhatsappIngestService
{
    public function __construct(
        private EvolutionWebhookNormalizer $normalizer,
        private WhatsappConversaService $conversas,
        private WhatsappSender $sender,
    ) {}

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

        // Eco das nossas próprias mensagens. O dedupe por message_id acima não
        // basta: se a Evolution não devolveu o id no envio, a linha 'sistema'
        // ficou sem message_id e o eco não casa com nada. Sem esta checagem, o
        // assistente leria a própria pergunta como resposta do usuário.
        if ($fromMe && $this->ehEcoDaAplicacao($instancia, $conteudo)) {
            $this->casarEcoComMensagemDoSistema($instancia, $messageId, (string) $conteudo['texto']);

            return;
        }

        // Base64 da mídia (webhookBase64): usado só para salvar a foto de
        // refeição — nunca vai para o raw_payload (bloat no banco).
        $mediaBase64 = (string) ($norm['image']['base64'] ?? '');
        unset($norm['image']['base64']);

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

        $this->detectarInboxPessoal($chat, $mensagem, $instancia, $mediaBase64);
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

    /**
     * "Apagar para todos": marca a mensagem e, quando foi o contato que apagou,
     * agenda o aviso no seu próprio número.
     *
     * A marca é gravada mesmo quando o aviso não vai sair — é ela que faz o
     * dedupe (o mesmo revoke chega em dois formatos de evento) e que deixa o
     * histórico honesto no painel.
     *
     * Mensagem que não está no banco é ignorada em silêncio: sem o conteúdo
     * original o aviso não teria o que mostrar, e a Evolution roda com
     * DATABASE_SAVE_DATA_HISTORIC=false — não há de onde recuperar.
     *
     * @param  array{messageId: string, remoteJid: string, porMim: bool}  $revoke
     */
    public function marcarApagada(array $revoke, WhatsappInstancia $instancia): void
    {
        $messageId = (string) ($revoke['messageId'] ?? '');
        if ($messageId === '') {
            return;
        }

        $mensagem = WhatsappMensagem::with('chat')
            ->where('instancia_id', $instancia->id)
            ->where('message_id', $messageId)
            ->first();

        if ($mensagem === null || $mensagem->apagada_em !== null) {
            return;
        }

        $mensagem->update(['apagada_em' => now()]);

        // Apagar coisa sua não é notícia — inclusive no chat consigo mesmo.
        if (! $instancia->aviso_apagadas_ativo || $mensagem->from_me) {
            return;
        }

        if ($mensagem->chat === null || $mensagem->chat->is_group) {
            return;
        }

        // Na fila porque o webhook precisa responder rápido: com a sessão caída,
        // o sendText só volta depois do timeout de 60s.
        dispatch(new AvisarMensagemApagada($mensagem->id));
    }

    /**
     * "Editar mensagem": guarda a versão anterior, passa o texto atual a ser o
     * que o celular mostra e, quando foi o contato que editou, agenda o aviso
     * no seu próprio número.
     *
     * O dedupe aqui é o próprio texto — não uma marca de tempo. O mesmo edit
     * pode chegar em dois formatos, mas uma segunda edição de verdade tem que
     * avisar de novo, e o que a distingue da repetição é justamente o conteúdo
     * ser outro. `texto_original` é escrito uma vez só: mesmo depois de três
     * edições, ele guarda o que a pessoa escreveu primeiro.
     *
     * Mensagem que não está no banco é ignorada em silêncio: sem a versão
     * anterior o aviso não teria o "antes" para mostrar.
     *
     * @param  array{messageId: string, remoteJid: string, porMim: bool, texto: string}  $edicao
     */
    public function marcarEditada(array $edicao, WhatsappInstancia $instancia): void
    {
        $messageId = (string) ($edicao['messageId'] ?? '');
        $novoTexto = trim((string) ($edicao['texto'] ?? ''));
        if ($messageId === '' || $novoTexto === '') {
            return;
        }

        $mensagem = WhatsappMensagem::with('chat')
            ->where('instancia_id', $instancia->id)
            ->where('message_id', $messageId)
            ->first();

        if ($mensagem === null) {
            return;
        }

        $anterior = (string) $mensagem->texto;
        if ($novoTexto === $anterior) {
            return;
        }

        $mensagem->update([
            'texto' => $novoTexto,
            'texto_original' => $mensagem->texto_original ?? $anterior,
            'editada_em' => now(),
        ]);

        // O resumo do chat é desnormalizado: sem isto a lista de conversas
        // seguiria exibindo um texto que já mudou no celular.
        $chat = $mensagem->chat;
        if ($chat !== null && $chat->last_message_id === $mensagem->message_id) {
            $chat->update(['last_message_text' => $novoTexto]);
        }

        // Editar coisa sua não é notícia — inclusive no chat consigo mesmo.
        if (! $instancia->aviso_edicoes_ativo || $mensagem->from_me) {
            return;
        }

        if ($chat === null || $chat->is_group) {
            return;
        }

        // Na fila pelo mesmo motivo do aviso de apagadas: o webhook precisa
        // responder rápido. O texto anterior vai junto porque a coluna já foi
        // sobrescrita — e numa segunda edição o "antes" é a versão que acabou
        // de sair, não a original.
        dispatch(new AvisarMensagemEditada($mensagem->id, $anterior));
    }

    /**
     * O texto bate com algo que acabamos de enviar? Consome a marca (pull) para
     * que uma mensagem idêntica digitada por você depois não seja descartada.
     *
     * @param  array<string, mixed>  $conteudo
     */
    private function ehEcoDaAplicacao(WhatsappInstancia $instancia, array $conteudo): bool
    {
        $texto = (string) ($conteudo['texto'] ?? '');
        if ($conteudo['tipo'] !== 'text' || $texto === '') {
            return false;
        }

        return Cache::pull(WhatsappSender::chaveEco($instancia->id, $texto)) !== null;
    }

    /**
     * Completa o message_id da linha 'sistema' que o sender gravou sem id — sem
     * isso ela fica órfã e o chat perde o vínculo com a mensagem real.
     */
    private function casarEcoComMensagemDoSistema(WhatsappInstancia $instancia, string $messageId, string $texto): void
    {
        if ($messageId === '') {
            return;
        }

        $orfa = WhatsappMensagem::where('instancia_id', $instancia->id)
            ->whereNull('message_id')
            ->where('origem', 'sistema')
            ->where('texto', $texto)
            ->latest('id')
            ->first();

        $orfa?->update(['message_id' => $messageId]);
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
     * Assistente do chat consigo mesmo: texto vira proposta de tarefa/refeição,
     * foto abre o menu "refeição ou comprovante". Nada é gravado aqui — este
     * método só decide o próximo passo da conversa.
     *
     * A decisão acontece sob lock (WhatsappConversaService::transicionar) para
     * que duas mensagens em sequência rápida não abram duas propostas. Enviar e
     * despachar ficam FORA da transação, com a decisão já commitada.
     */
    private function detectarInboxPessoal(
        WhatsappChat $chat,
        WhatsappMensagem $mensagem,
        WhatsappInstancia $instancia,
        string $mediaBase64,
    ): void {
        if (! $mensagem->from_me || ! $this->ehChatComigo($chat, $instancia)) {
            return;
        }

        // Cinto extra contra loop: mensagens da aplicação já são filtradas pelo
        // dedupe e pelo hash do eco, mas um prefixo nosso nunca é entrada.
        if ($mensagem->origem === 'sistema' || $this->pareceMensagemDoBot($mensagem)) {
            return;
        }

        $decisao = match ($mensagem->tipo) {
            'text' => $this->decidirTexto($chat, $mensagem, $instancia),
            'image' => $this->decidirImagem($chat, $mensagem, $instancia, $mediaBase64),
            default => $this->decidirOutraMidia($mensagem, $instancia),
        };

        if ($decisao === null) {
            return;
        }

        if (($decisao['responder'] ?? null) !== null) {
            $this->sender->enviarParaMim($instancia, $decisao['responder']);
        }

        if (($decisao['job'] ?? null) !== null) {
            dispatch($decisao['job']);
        }
    }

    /**
     * @return array{responder: string|null, job: object|null}|null
     */
    private function decidirTexto(WhatsappChat $chat, WhatsappMensagem $mensagem, WhatsappInstancia $instancia): ?array
    {
        $texto = trim((string) $mensagem->texto);
        if ($texto === '' || ! $instancia->gtd_ativo) {
            return null;
        }

        return $this->conversas->transicionar($instancia, function (WhatsappConversa $conversa) use ($chat, $mensagem, $texto) {
            if ($conversa->estado === WhatsappConversa::PROCESSANDO) {
                return ['responder' => '⏳ Só um segundo, ainda estou terminando o anterior.', 'job' => null];
            }

            // "sim"/"não" solto sem nada pendente: resposta perdida, não é nota.
            // Sem isso, um aceite atrasado viraria uma tarefa chamada "sim".
            if ($conversa->estaOciosa() && WhatsappRespostaParser::ehRespostaSolta($texto)) {
                return ['responder' => '🤷 Não tenho nada pendente por aqui.', 'job' => null];
            }

            $conversa->chat_id = $chat->id;
            $this->conversas->marcarProcessando($conversa, $mensagem->id);

            return ['responder' => null, 'job' => new ProcessarMensagemPessoal($mensagem->id)];
        });
    }

    /**
     * Foto: o tipo é perguntado ANTES de qualquer chamada de IA. Com só uma
     * capacidade ligada não há o que perguntar — vai direto para o fluxo.
     *
     * @return array{responder: string|null, job: object|null}|null
     */
    private function decidirImagem(
        WhatsappChat $chat,
        WhatsappMensagem $mensagem,
        WhatsappInstancia $instancia,
        string $mediaBase64,
    ): ?array {
        $fluxos = array_values(array_filter([
            $instancia->calorias_foto_ativo ? WhatsappConversa::FLUXO_REFEICAO : null,
            $instancia->financeiro_ativo ? WhatsappConversa::FLUXO_COMPROVANTE : null,
        ]));

        if ($fluxos === []) {
            return null;
        }

        $path = $this->guardarAnexo($mensagem, $instancia, $mediaBase64);
        if ($path === null) {
            return null;
        }

        return $this->conversas->transicionar($instancia, function (WhatsappConversa $conversa) use ($chat, $mensagem, $path, $fluxos) {
            // Foto sempre ganha da pendência anterior (o abrir() apaga o anexo
            // antigo). Álbuns chegam como eventos separados: fica a última.
            $substituiu = $conversa->anexo_path !== null;

            if (count($fluxos) === 1) {
                $this->conversas->abrir(
                    conversa: $conversa,
                    estado: WhatsappConversa::PROCESSANDO,
                    fluxo: $fluxos[0],
                    mensagemId: $mensagem->id,
                    anexoPath: $path,
                    chatId: $chat->id,
                );

                return [
                    'responder' => $substituiu ? '📷 Peguei a última foto (descartei a anterior).' : null,
                    'job' => new AnalisarAnexoWhatsapp($mensagem->id, $fluxos[0]),
                ];
            }

            $opcoes = [
                ['chave' => WhatsappConversa::FLUXO_REFEICAO, 'label' => 'Refeição'],
                ['chave' => WhatsappConversa::FLUXO_COMPROVANTE, 'label' => 'Comprovante'],
                ['chave' => 'cancelar', 'label' => 'Cancelar'],
            ];

            $this->conversas->abrir(
                conversa: $conversa,
                estado: WhatsappConversa::AGUARDANDO_TIPO_MIDIA,
                mensagemId: $mensagem->id,
                payload: ['opcoes' => $opcoes],
                anexoPath: $path,
                chatId: $chat->id,
            );

            $aviso = $substituiu ? "📷 Peguei a última foto (descartei a anterior).\n\n" : '';

            return [
                'responder' => $aviso.'📷 Essa foto é:  1️⃣ Refeição   2️⃣ Comprovante   3️⃣ Cancelar',
                'job' => null,
            ];
        });
    }

    /**
     * Áudio, vídeo, documento e sticker: avisa uma vez e deixa a pendência
     * intacta — o contrário travaria a conversa esperando resposta.
     *
     * @return array{responder: string|null, job: object|null}|null
     */
    private function decidirOutraMidia(WhatsappMensagem $mensagem, WhatsappInstancia $instancia): ?array
    {
        if (! $instancia->gtd_ativo && ! $instancia->calorias_foto_ativo && ! $instancia->financeiro_ativo) {
            return null;
        }

        return ['responder' => '🙉 Por enquanto só entendo texto e foto por aqui.', 'job' => null];
    }

    /**
     * Salva a foto em staging. Só no commit ela é movida para o diretório do
     * lançamento (refeição ou comprovante), que têm regras de posse distintas.
     */
    private function guardarAnexo(WhatsappMensagem $mensagem, WhatsappInstancia $instancia, string $mediaBase64): ?string
    {
        if ($mediaBase64 === '') {
            Log::warning('[whatsapp:inbox] imagem sem base64 no webhook — reconfigure o webhook da instância para aplicar webhookBase64.');

            return null;
        }

        $binario = base64_decode($mediaBase64, true);
        if ($binario === false || $binario === '') {
            Log::warning("[whatsapp:inbox] base64 inválido na mensagem {$mensagem->id}.");

            return null;
        }

        $path = "whatsapp/pendentes/{$instancia->user_id}/".Str::uuid().'.'.self::extensaoDoMime((string) $mensagem->media_mime);
        Storage::disk('local')->put($path, $binario);

        return $path;
    }

    public static function extensaoDoMime(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private function pareceMensagemDoBot(WhatsappMensagem $mensagem): bool
    {
        $texto = trim((string) $mensagem->texto);

        foreach ((array) config('whatsapp.conversa.prefixos_bot', []) as $prefixo) {
            if ($prefixo !== '' && str_starts_with($texto, (string) $prefixo)) {
                return true;
            }
        }

        return false;
    }

    private function ehChatComigo(WhatsappChat $chat, WhatsappInstancia $instancia): bool
    {
        return ! $chat->is_group
            && $chat->phone !== null
            && $instancia->phone !== null
            && $chat->phone === $instancia->phone;
    }
}
