<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Aviso de "editar mensagem": quando um contato troca o texto de uma mensagem,
 * as duas versões chegam no seu próprio número — o "antes" que o WhatsApp
 * esconde e o "agora" que ficou no lugar.
 *
 * Os testes entram pelo webhook HTTP de verdade, com o WhatsappSender espionado,
 * para cobrir o caminho inteiro — payload da Evolution até o texto que você leria.
 */
class WhatsappEditadaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> textos que o bot mandou, na ordem */
    private array $enviadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.webhook.token' => 'tok']);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')
            ->andReturnUsing(function ($instancia, string $texto) {
                $this->enviadas[] = $texto;

                return true;
            });
        $this->app->instance(WhatsappSender::class, $sender);
    }

    public function test_contato_edita_mensagem_e_as_duas_versoes_chegam_no_seu_numero(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'te amo');

        $this->editar($instancia, 'MSG-1', 'te odeio');

        $mensagem = $mensagem->fresh();
        $this->assertNotNull($mensagem->editada_em);
        // O texto passa a ser o que o celular mostra; o primeiro fica guardado.
        $this->assertSame('te odeio', $mensagem->texto);
        $this->assertSame('te amo', $mensagem->texto_original);

        $this->assertCount(1, $this->enviadas);
        $this->assertUltima('✏️ *Mensagem editada*');
        $this->assertUltima('*Fulano*');
        $this->assertUltima('te amo');
        $this->assertUltima('te odeio');
    }

    /**
     * O mesmo edit pode chegar nos dois formatos (e a Evolution pode reenviar):
     * o texto já gravado é o que garante um aviso só.
     */
    public function test_edit_repetido_nos_dois_formatos_avisa_uma_vez_so(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $this->mensagemRecebida($instancia, 'MSG-1', 'antes');

        $this->editar($instancia, 'MSG-1', 'depois');
        $this->editar($instancia, 'MSG-1', 'depois');
        $this->editarViaUpsert($instancia, 'MSG-1', 'depois');

        $this->assertCount(1, $this->enviadas);
    }

    /**
     * Uma segunda edição de verdade avisa de novo — e o "antes" é a versão que
     * acabou de sair, não a original, que continua guardada na coluna.
     */
    public function test_segunda_edicao_avisa_de_novo_com_a_versao_que_saiu(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'versão 1');

        $this->editar($instancia, 'MSG-1', 'versão 2');
        $this->editar($instancia, 'MSG-1', 'versão 3');

        $this->assertCount(2, $this->enviadas);
        $this->assertUltima('versão 2');
        $this->assertUltima('versão 3');
        $this->assertStringNotContainsString('versão 1', (string) end($this->enviadas));

        $mensagem = $mensagem->fresh();
        $this->assertSame('versão 3', $mensagem->texto);
        $this->assertSame('versão 1', $mensagem->texto_original);
    }

    /** O formato aninhado no upsert também precisa funcionar sozinho. */
    public function test_protocol_message_no_upsert_dispara_o_aviso(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $this->mensagemRecebida($instancia, 'MSG-1', 'original');

        $this->editarViaUpsert($instancia, 'MSG-1', 'editada via upsert');

        $this->assertUltima('editada via upsert');
    }

    /**
     * A Evolution emite MESSAGES_EDITED para qualquer protocolMessage, revoke
     * incluído. Sem conteúdo novo não é edição — e a mensagem fica intacta.
     */
    public function test_revoke_chegando_como_edited_nao_vira_edicao(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'original');

        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.edited',
            'instance' => $instancia->instance_name,
            'data' => [
                'key' => ['remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-1'],
                'type' => 'REVOKE',
            ],
        ])->assertOk();

        $this->assertSame([], $this->enviadas);
        $this->assertNull($mensagem->fresh()->editada_em);
        $this->assertSame('original', $mensagem->fresh()->texto);
    }

    /** Editar coisa sua não é notícia — mas o histórico registra. */
    public function test_mensagem_sua_editada_grava_mas_nao_avisa(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'nota minha', fromMe: true);

        $this->editar($instancia, 'MSG-1', 'nota minha corrigida', porMim: true);

        $mensagem = $mensagem->fresh();
        $this->assertNotNull($mensagem->editada_em);
        $this->assertSame('nota minha corrigida', $mensagem->texto);
        $this->assertSame([], $this->enviadas);
    }

    public function test_grupo_grava_mas_nao_avisa(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $chat = WhatsappChat::create([
            'instancia_id' => $instancia->id,
            'chave' => '120363000000000000@g.us',
            'chat_lid' => '120363000000000000@g.us',
            'chat_name' => 'Família',
            'is_group' => true,
        ]);
        $mensagem = $this->mensagemNoChat($instancia, $chat, 'MSG-G', 'mensagem de grupo');

        $this->editar($instancia, 'MSG-G', 'mensagem de grupo editada', remoteJid: '120363000000000000@g.us');

        $this->assertNotNull($mensagem->fresh()->editada_em);
        $this->assertSame([], $this->enviadas);
    }

    /**
     * Sem a mensagem no banco não há "antes" para mostrar — e a Evolution roda
     * com DATABASE_SAVE_DATA_HISTORIC=false, então não há de onde recuperar.
     */
    public function test_mensagem_desconhecida_nao_gera_aviso(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->editar($instancia, 'MSG-QUE-NUNCA-CHEGOU', 'texto novo');

        $this->assertSame([], $this->enviadas);
    }

    public function test_interruptor_desligado_grava_mas_nao_avisa(): void
    {
        [, $instancia] = $this->instanciaConectada(['aviso_edicoes_ativo' => false]);
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'antes');

        $this->editar($instancia, 'MSG-1', 'depois');

        $mensagem = $mensagem->fresh();
        $this->assertNotNull($mensagem->editada_em);
        $this->assertSame('depois', $mensagem->texto);
        $this->assertSame([], $this->enviadas);
    }

    /** Legenda de foto também é editável: o aviso usa o rótulo da mídia. */
    public function test_legenda_de_foto_editada_avisa_com_o_rotulo_da_midia(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-F', '[Imagem] olha isso');
        $mensagem->update(['tipo' => 'image']);

        $this->editar($instancia, 'MSG-F', null, editedMessage: [
            'imageMessage' => ['caption' => 'olha isso aqui'],
        ]);

        $this->assertUltima('[Imagem] olha isso');
        $this->assertUltima('[Imagem] olha isso aqui');
    }

    /**
     * O resumo do chat é desnormalizado: sem atualizar, a lista de conversas
     * mostraria um texto que já mudou no celular.
     */
    public function test_edicao_atualiza_o_texto_da_ultima_mensagem_do_chat(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'antes');
        $mensagem->chat->update(['last_message_id' => 'MSG-1', 'last_message_text' => 'antes']);

        $this->editar($instancia, 'MSG-1', 'depois');

        $this->assertSame('depois', $mensagem->chat->fresh()->last_message_text);
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * @param  array<string, mixed>  $flags
     * @return array{0: User, 1: WhatsappInstancia}
     */
    private function instanciaConectada(array $flags = []): array
    {
        $user = User::factory()->create();
        $instancia = WhatsappInstancia::create($flags + [
            'user_id' => $user->id,
            'instance_name' => 'inst-'.$user->id,
            'phone' => '5511999999999',
            'status' => 'conectado',
            'aviso_edicoes_ativo' => true,
        ]);

        return [$user, $instancia];
    }

    private function mensagemRecebida(
        WhatsappInstancia $instancia,
        string $messageId,
        string $texto,
        bool $fromMe = false,
    ): WhatsappMensagem {
        $chat = WhatsappChat::firstOrCreate(
            ['instancia_id' => $instancia->id, 'chave' => '5511988887777'],
            ['phone' => '5511988887777', 'sender_name' => 'Fulano', 'is_group' => false],
        );

        return $this->mensagemNoChat($instancia, $chat, $messageId, $texto, $fromMe);
    }

    private function mensagemNoChat(
        WhatsappInstancia $instancia,
        WhatsappChat $chat,
        string $messageId,
        string $texto,
        bool $fromMe = false,
    ): WhatsappMensagem {
        return WhatsappMensagem::create([
            'chat_id' => $chat->id,
            'instancia_id' => $instancia->id,
            'message_id' => $messageId,
            'phone' => $chat->phone,
            'from_me' => $fromMe,
            'sender_name' => 'Fulano',
            'tipo' => 'text',
            'texto' => $texto,
            'status' => $fromMe ? 'SENT' : 'RECEIVED',
            'momment' => now()->getTimestampMs(),
        ]);
    }

    /**
     * messages.edited: o data É o protocolMessage que a Evolution repassa.
     *
     * @param  array<string, mixed>|null  $editedMessage  conteúdo cru, quando não for texto simples
     */
    private function editar(
        WhatsappInstancia $instancia,
        string $messageId,
        ?string $texto,
        bool $porMim = false,
        string $remoteJid = '5511988887777@s.whatsapp.net',
        ?array $editedMessage = null,
    ): void {
        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.edited',
            'instance' => $instancia->instance_name,
            'data' => [
                'key' => ['remoteJid' => $remoteJid, 'fromMe' => $porMim, 'id' => $messageId],
                'type' => 'MESSAGE_EDIT',
                'editedMessage' => $editedMessage ?? ['conversation' => (string) $texto],
                'timestampMs' => (string) now()->getTimestampMs(),
            ],
        ])->assertOk();
    }

    /** O mesmo edit aninhado num messages.upsert de protocolMessage. */
    private function editarViaUpsert(WhatsappInstancia $instancia, string $messageId, string $texto): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.upsert',
            'instance' => $instancia->instance_name,
            'data' => [
                'key' => [
                    'remoteJid' => '5511988887777@s.whatsapp.net',
                    'fromMe' => false,
                    'id' => 'EDIT-'.$messageId,
                ],
                'messageTimestamp' => now()->timestamp,
                'messageType' => 'protocolMessage',
                'message' => [
                    'protocolMessage' => [
                        'type' => 14,
                        'key' => ['id' => $messageId],
                        'editedMessage' => ['conversation' => $texto],
                    ],
                ],
            ],
        ])->assertOk();
    }

    private function assertUltima(string $trecho): void
    {
        $this->assertNotEmpty($this->enviadas, "Nenhum aviso enviado (esperava conter \"{$trecho}\").");
        $ultima = end($this->enviadas);
        $this->assertStringContainsString($trecho, $ultima, "Último aviso:\n{$ultima}");
    }
}
