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
 * Aviso de "apagar para todos": quando um contato apaga uma mensagem, o texto
 * que sumiu do celular chega no seu próprio número com o nome de quem apagou.
 *
 * Os testes entram pelo webhook HTTP de verdade, com o WhatsappSender espionado,
 * para cobrir o caminho inteiro — payload da Evolution até o texto que você leria.
 */
class WhatsappApagadaTest extends TestCase
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

    public function test_contato_apaga_mensagem_e_o_texto_chega_no_seu_numero(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'era pra ser um segredo');

        $this->apagar($instancia, 'MSG-1');

        $this->assertNotNull($mensagem->fresh()->apagada_em);
        $this->assertCount(1, $this->enviadas);
        $this->assertUltima('🗑️ *Mensagem apagada*');
        $this->assertUltima('*Fulano*');
        $this->assertUltima('era pra ser um segredo');
    }

    /**
     * O mesmo revoke chega nos dois formatos (e a Evolution pode reenviar): a
     * marca apagada_em é o que garante um aviso só.
     */
    public function test_revoke_repetido_nos_dois_formatos_avisa_uma_vez_so(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $this->mensagemRecebida($instancia, 'MSG-1', 'sumiu');

        $this->apagar($instancia, 'MSG-1');
        $this->apagar($instancia, 'MSG-1');
        $this->apagarViaProtocolMessage($instancia, 'MSG-1');

        $this->assertCount(1, $this->enviadas);
    }

    /**
     * O formato protocolMessage sozinho também precisa funcionar: se a
     * reassinatura do webhook não pegou, é o único que chega.
     */
    public function test_protocol_message_sozinho_dispara_o_aviso(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $this->mensagemRecebida($instancia, 'MSG-1', 'apagada via protocolo');

        $this->apagarViaProtocolMessage($instancia, 'MSG-1');

        $this->assertUltima('apagada via protocolo');
    }

    /** Apagar coisa sua não é notícia — mas o histórico registra. */
    public function test_mensagem_sua_apagada_marca_mas_nao_avisa(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'nota minha', fromMe: true);

        $this->apagar($instancia, 'MSG-1', porMim: true);

        $this->assertNotNull($mensagem->fresh()->apagada_em);
        $this->assertSame([], $this->enviadas);
    }

    public function test_grupo_marca_mas_nao_avisa(): void
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

        $this->apagar($instancia, 'MSG-G', remoteJid: '120363000000000000@g.us');

        $this->assertNotNull($mensagem->fresh()->apagada_em);
        $this->assertSame([], $this->enviadas);
    }

    /**
     * Sem a mensagem no banco não há conteúdo para mostrar — e a Evolution roda
     * com DATABASE_SAVE_DATA_HISTORIC=false, então não há de onde recuperar.
     */
    public function test_mensagem_desconhecida_nao_gera_aviso(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->apagar($instancia, 'MSG-QUE-NUNCA-CHEGOU');

        $this->assertSame([], $this->enviadas);
    }

    public function test_interruptor_desligado_marca_mas_nao_avisa(): void
    {
        [, $instancia] = $this->instanciaConectada(['aviso_apagadas_ativo' => false]);
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-1', 'silêncio');

        $this->apagar($instancia, 'MSG-1');

        $this->assertNotNull($mensagem->fresh()->apagada_em);
        $this->assertSame([], $this->enviadas);
    }

    /** Mídia não tem texto próprio: o aviso usa o rótulo do normalizador. */
    public function test_foto_apagada_avisa_com_o_rotulo_da_midia(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemRecebida($instancia, 'MSG-F', '[Imagem] olha isso');
        $mensagem->update(['tipo' => 'image']);

        $this->apagar($instancia, 'MSG-F');

        $this->assertUltima('[Imagem] olha isso');
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
            'aviso_apagadas_ativo' => true,
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

    private function apagar(
        WhatsappInstancia $instancia,
        string $messageId,
        bool $porMim = false,
        string $remoteJid = '5511988887777@s.whatsapp.net',
    ): void {
        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.delete',
            'instance' => $instancia->instance_name,
            'data' => ['remoteJid' => $remoteJid, 'fromMe' => $porMim, 'id' => $messageId],
        ])->assertOk();
    }

    private function apagarViaProtocolMessage(WhatsappInstancia $instancia, string $messageId): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.upsert',
            'instance' => $instancia->instance_name,
            'data' => [
                'key' => [
                    'remoteJid' => '5511988887777@s.whatsapp.net',
                    'fromMe' => false,
                    'id' => 'REVOKE-'.$messageId,
                ],
                'messageTimestamp' => now()->timestamp,
                'messageType' => 'protocolMessage',
                'message' => [
                    'protocolMessage' => [
                        'type' => 'REVOKE',
                        'key' => ['id' => $messageId],
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
