<?php

namespace Tests\Feature;

use App\Jobs\ProcessarMensagemPessoal;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\FinanceCategory;
use App\Models\Payable;
use App\Models\SaudeRefeicao;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use App\Services\ReceiptAI\ReceiptParser;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Whatsapp\WhatsappConversaAI;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Assistente do chat consigo mesmo: o bot propõe e só grava depois do seu "sim".
 *
 * Os testes entram pelo webhook HTTP de verdade (não chamando o ingest direto),
 * com o WhatsappSender espionado — assim a asserção é sobre a conversa inteira,
 * do payload da Evolution até o texto que você receberia no celular.
 */
class WhatsappConversaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> textos que o bot mandou, na ordem */
    private array $enviadas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['whatsapp.webhook.token' => 'tok']);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')
            ->andReturnUsing(function ($instancia, string $texto) {
                $this->enviadas[] = $texto;

                return true;
            });
        $this->app->instance(WhatsappSender::class, $sender);
    }

    // ============================================================
    // Texto → tarefa
    // ============================================================

    public function test_nota_de_texto_vira_proposta_e_so_grava_depois_do_sim(): void
    {
        [$user, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
            'titulo' => 'Ligar pro dentista',
            'descricao' => null,
            'prioridade' => 'medium',
            'due_date' => '2026-08-15',
        ]]);

        $this->receber('ligar pro dentista sexta');

        // Propôs, mas não gravou nada ainda — este é o ponto da mudança.
        $this->assertSame(0, Task::count());
        $this->assertUltima('📋 *Tarefa:* Ligar pro dentista');
        $this->assertUltima('prazo 15/08/2026');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $instancia);

        $this->receber('sim');

        $task = Task::sole();
        $this->assertSame('Ligar pro dentista', $task->title);
        $this->assertSame($user->id, $task->user_id);
        $this->assertUltima('✅ Tarefa criada: Ligar pro dentista');
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    public function test_nao_cancela_a_proposta_sem_gravar_nada(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
            'titulo' => 'Renovar o domínio', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
        ]]);

        $this->receber('renovar o domínio do site');
        $this->receber('não');

        $this->assertSame(0, Task::count());
        $this->assertUltima('não gravei nada');
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    /**
     * "sim" isolado depois de uma expiração não é uma nota — sem esta guarda,
     * viraria uma tarefa chamada "sim".
     */
    public function test_sim_solto_sem_pendencia_nao_cria_tarefa(): void
    {
        $this->instanciaConectada();
        $this->mockRoteadorNuncaChamado();

        $this->receber('sim');

        $this->assertSame(0, Task::count());
        $this->assertUltima('Não tenho nada pendente');
    }

    // ============================================================
    // Texto → refeição
    // ============================================================

    public function test_refeicao_por_texto_e_confirmada_antes_de_gravar(): void
    {
        [$user, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(['tipo' => 'refeicao', 'tarefa' => null, 'refeicao' => $this->analiseFake()]);

        $this->receber('comi 2 ovos mexidos e café com leite');

        $this->assertSame(0, SaudeRefeicao::count());
        $this->assertUltima('Ovos mexidos com café');
        $this->assertUltima('280 kcal');

        $this->receber('sim');

        $refeicao = SaudeRefeicao::sole();
        $this->assertSame('whatsapp_texto', $refeicao->origem);
        $this->assertSame(280, $refeicao->calorias);
        $this->assertSame($user->id, $refeicao->user_id);
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    /**
     * O horário vem da mensagem ORIGINAL, não do "sim": senão um almoço
     * confirmado à noite entraria no diário como jantar.
     */
    public function test_refeicao_usa_o_horario_da_mensagem_original(): void
    {
        $this->instanciaConectada();
        $this->mockRoteador(['tipo' => 'refeicao', 'tarefa' => null, 'refeicao' => $this->analiseFake()]);

        // Mandou no almoço e só confirmou à noite.
        $this->travelTo(now(config('saude.timezone'))->setTime(20, 0));
        $this->receber('comi um PF', momento: now(config('saude.timezone'))->setTime(12, 30));
        $this->receber('sim');

        $this->assertSame('12:30:00', SaudeRefeicao::sole()->horario);
    }

    // ============================================================
    // Foto → menu, sem gastar IA antes de saber o tipo
    // ============================================================

    public function test_foto_pergunta_o_tipo_antes_de_qualquer_chamada_de_ia(): void
    {
        [$user, $instancia] = $this->instanciaConectada(['financeiro_ativo' => true]);

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldNotReceive('analisarFoto');
        $this->app->instance(SaudeNutricaoAI::class, $ia);

        $parser = Mockery::mock(ReceiptParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(ReceiptParser::class, $parser);

        $this->receberFoto();

        $this->assertUltima('📷 Essa foto é:');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_TIPO_MIDIA, $instancia);

        // A foto ficou em staging, longe dos diretórios de lançamento.
        $this->assertCount(1, Storage::disk('local')->files("whatsapp/pendentes/{$user->id}"));
        $this->assertSame([], Storage::disk('local')->files("saude/refeicoes/{$user->id}"));
    }

    /**
     * Com só uma capacidade de foto ligada não há o que perguntar.
     */
    public function test_foto_vai_direto_ao_fluxo_quando_so_uma_capacidade_esta_ativa(): void
    {
        [, $instancia] = $this->instanciaConectada(['financeiro_ativo' => false]);
        $this->mockVisao($this->analiseFake());

        $this->receberFoto();

        $this->assertUltima('Ovos mexidos com café');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $instancia);
        $this->assertSame(0, SaudeRefeicao::count());
    }

    public function test_foto_de_refeicao_promovida_para_o_diretorio_da_saude_no_commit(): void
    {
        [$user, $instancia] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $this->mockVisao($this->analiseFake());

        $this->receberFoto();
        $this->receber('1');
        $this->receber('sim');

        $refeicao = SaudeRefeicao::sole();
        $this->assertSame('whatsapp_foto', $refeicao->origem);
        $this->assertStringStartsWith("saude/refeicoes/{$user->id}/", (string) $refeicao->foto_path);
        Storage::disk('local')->assertExists($refeicao->foto_path);
        // Staging esvaziado: o arquivo foi movido, não copiado.
        $this->assertSame([], Storage::disk('local')->files("whatsapp/pendentes/{$user->id}"));
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    public function test_cancelar_no_menu_da_foto_apaga_o_staging(): void
    {
        [$user, $instancia] = $this->instanciaConectada(['financeiro_ativo' => true]);

        $this->receberFoto();
        $this->receber('3');

        $this->assertUltima('Descartei a foto');
        $this->assertSame([], Storage::disk('local')->files("whatsapp/pendentes/{$user->id}"));
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    // ============================================================
    // Foto → comprovante
    // ============================================================

    public function test_comprovante_lancado_em_conta_debita_o_saldo(): void
    {
        [$user, $instancia] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $itau = $this->conta($user, 'Itaú', 1000.00);
        $this->conta($user, 'Nubank', 500.00);
        $categoria = $this->categoria($user, 'Mercado');

        $this->mockReceipt($this->comprovanteFake($categoria->id));

        $this->receberFoto();
        $this->receber('2');

        // Duas contas e pagamento em pix: não dá para adivinhar, então pergunta.
        $this->assertUltima('🧾 *Supermercado Zaffari*');
        $this->assertUltima('R$ 187,40');
        $this->assertUltima('Mercado');
        $this->assertUltima('Onde eu lanço?');
        $this->assertSame(0, Payable::count());

        // Uma única mensagem resolve aceite + destino.
        $this->mockInterpretacao(['acao' => 'confirmar', 'destino' => "conta:{$itau->id}", 'correcoes' => null, 'resumo_correcao' => null]);
        $this->receber('Sim, lançar do Itaú');

        $payable = Payable::sole();
        $this->assertSame('Supermercado Zaffari', $payable->description);
        $this->assertEquals(187.40, $payable->amount);
        $this->assertTrue((bool) $payable->is_paid);
        $this->assertSame($itau->id, $payable->bank_account_id);
        $this->assertSame($categoria->id, $payable->category_id);
        $this->assertStringStartsWith("receipts/{$user->id}/", (string) $payable->receipt_path);
        Storage::disk('local')->assertExists($payable->receipt_path);

        $this->assertEquals(812.60, $itau->fresh()->balance);
        $this->assertUltima('✅ Lançado: Supermercado Zaffari');
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    /**
     * Com uma conta só e pagamento em pix, o destino é óbvio — não perguntar
     * economiza um turno inteiro.
     */
    public function test_com_uma_conta_so_o_destino_ja_vem_sugerido(): void
    {
        [$user] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $itau = $this->conta($user, 'Itaú', 300.00);

        $this->mockReceipt($this->comprovanteFake(null));

        $this->receberFoto();
        $this->receber('2');

        $this->assertUltima('Lançar em: *Itaú*');

        $this->receber('sim');

        $this->assertSame($itau->id, Payable::sole()->bank_account_id);
        $this->assertEquals(112.60, $itau->fresh()->balance);
    }

    public function test_comprovante_no_cartao_cai_na_fatura_e_nao_mexe_no_saldo(): void
    {
        [$user] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $conta = $this->conta($user, 'Itaú', 1000.00);
        $cartao = $this->cartao($user, 'Nubank', '1234');

        // Últimos 4 dígitos batem com o cartão cadastrado: destino resolvido sozinho.
        $this->mockReceipt($this->comprovanteFake(null, ['payment_method' => 'credito'], '1234'));

        $this->receberFoto();
        $this->receber('2');

        $this->assertUltima('Lançar em: *cartão Nubank*');

        $this->receber('sim');

        $transacao = CreditCardTransaction::sole();
        $this->assertSame($cartao->id, $transacao->credit_card_id);
        $this->assertEquals(187.40, $transacao->amount);
        $this->assertNotNull($transacao->credit_card_invoice_id);
        $this->assertSame(0, Payable::count());
        $this->assertEquals(1000.00, $conta->fresh()->balance);
    }

    public function test_fatura_com_varios_itens_e_recusada_e_aponta_o_painel(): void
    {
        [$user] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $this->conta($user, 'Itaú', 1000.00);

        $this->mockReceipt([
            'document_type' => 'fatura',
            'card_last_four' => null,
            'items' => [$this->itemFake(), $this->itemFake(), $this->itemFake()],
        ]);

        $this->receberFoto();
        $this->receber('2');

        $this->assertUltima('fatura/extrato com 3 lançamentos');
        $this->assertUltima('painel');
        $this->assertSame(0, Payable::count());
        $this->assertSame([], Storage::disk('local')->files("whatsapp/pendentes/{$user->id}"));
    }

    public function test_comprovante_duplicado_nao_lanca_de_novo(): void
    {
        [$user] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $itau = $this->conta($user, 'Itaú', 1000.00);
        $this->mockReceipt($this->comprovanteFake(null));

        $this->receberFoto();
        $this->receber('2');
        $this->receber('sim');

        $this->assertSame(1, Payable::count());

        // Mesmo comprovante de novo (reenvio, mensagem repetida).
        $this->receberFoto();
        $this->receber('2');
        $this->receber('sim');

        $this->assertSame(1, Payable::count());
        $this->assertUltima('já existe');
        $this->assertEquals(812.60, $itau->fresh()->balance);
    }

    // ============================================================
    // Correção em linguagem natural
    // ============================================================

    public function test_correcao_reapresenta_a_proposta_em_vez_de_gravar(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
            'titulo' => 'Ligar pro dentista', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
        ]]);

        $this->receber('ligar pro dentista');

        $this->mockInterpretacao([
            'acao' => 'corrigir',
            'destino' => null,
            'correcoes' => ['prioridade' => 'urgent'],
            'resumo_correcao' => 'Prioridade urgente',
        ]);
        $this->receber('na verdade é urgente');

        // Corrigir NUNCA grava direto: uma leitura errada da IA viraria dado ruim.
        $this->assertSame(0, Task::count());
        $this->assertUltima('Prioridade urgente');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $instancia);

        $this->receber('sim');

        $this->assertSame('urgent', Task::sole()->priority);
    }

    public function test_mudar_de_assunto_descarta_a_pendencia_e_propoe_a_nota_nova(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(
            ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
                'titulo' => 'Ligar pro dentista', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
            ]],
            ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
                'titulo' => 'Comprar pão', 'descricao' => null, 'prioridade' => 'low', 'due_date' => null,
            ]],
        );

        $this->receber('ligar pro dentista');

        $this->mockInterpretacao(['acao' => 'nova_mensagem', 'destino' => null, 'correcoes' => null, 'resumo_correcao' => null]);
        $this->receber('comprar pão');

        $this->assertSame(0, Task::count());
        $this->assertUltima('📋 *Tarefa:* Comprar pão');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $instancia);
    }

    public function test_ia_indisponivel_nunca_grava_e_cai_no_menu_numerado(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
            'titulo' => 'Ligar pro dentista', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
        ]]);

        $this->receber('ligar pro dentista');

        $this->mockInterpretacao(['acao' => 'nao_entendi', 'destino' => null, 'correcoes' => null, 'resumo_correcao' => null]);
        $this->receber('sei lá, talvez');

        $this->assertSame(0, Task::count());
        $this->assertUltima('1️⃣ Sim, pode gravar');
        $this->assertEstado(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $instancia);

        // O menu é respondível sem IA — é essa a saída de emergência.
        $this->receber('1');
        $this->assertSame(1, Task::count());
    }

    // ============================================================
    // Corridas, eco e expiração
    // ============================================================

    /**
     * Duas mensagens em sequência rápida não podem abrir duas propostas nem
     * (pior) gravar duas vezes o mesmo lançamento.
     */
    public function test_duas_mensagens_seguidas_geram_uma_unica_pendencia(): void
    {
        [, $instancia] = $this->instanciaConectada();
        Queue::fake();

        $this->receber('primeira nota');
        $this->receber('segunda nota');

        Queue::assertPushed(ProcessarMensagemPessoal::class, 1);
        $this->assertSame(1, WhatsappConversa::count());
        $this->assertEstado(WhatsappConversa::PROCESSANDO, $instancia);
        $this->assertUltima('Só um segundo');
    }

    /**
     * O eco das mensagens do próprio bot não pode voltar como entrada — senão
     * o assistente responderia à própria pergunta em loop.
     */
    public function test_eco_da_mensagem_do_bot_nao_vira_entrada(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $this->mockRoteadorNuncaChamado();

        $textoDoBot = '📋 *Tarefa:* Ligar pro dentista';
        Cache::put(WhatsappSender::chaveEco($instancia->id, $textoDoBot), true, now()->addMinutes(5));

        // Cenário do furo: a Evolution não devolveu messageId no envio, então a
        // linha 'sistema' ficou órfã e o eco não casa pelo dedupe.
        WhatsappMensagem::create([
            'chat_id' => $this->chatComigo($instancia)->id,
            'instancia_id' => $instancia->id,
            'message_id' => null,
            'phone' => $instancia->phone,
            'from_me' => true,
            'tipo' => 'text',
            'texto' => $textoDoBot,
            'momment' => now()->getTimestampMs(),
            'origem' => 'sistema',
        ]);

        $this->receber($textoDoBot, messageId: 'ECO-1');

        // Nada de novo entrou e a linha órfã ganhou o message_id que faltava.
        $this->assertSame(1, WhatsappMensagem::count());
        $this->assertSame('ECO-1', WhatsappMensagem::sole()->message_id);
        $this->assertSame([], $this->enviadas);
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    public function test_pendencia_expirada_faz_a_mensagem_seguinte_valer_como_nota_nova(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->mockRoteador(
            ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
                'titulo' => 'Ligar pro dentista', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
            ]],
            ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => [
                'titulo' => 'Comprar pão', 'descricao' => null, 'prioridade' => 'medium', 'due_date' => null,
            ]],
        );

        $this->receber('ligar pro dentista');
        $this->travelTo(now()->addMinutes((int) config('whatsapp.conversa.ttl_minutos') + 1));

        $this->receber('comprar pão');

        // A pendência velha morreu; a nota nova virou proposta própria.
        $this->assertSame(0, Task::count());
        $this->assertUltima('📋 *Tarefa:* Comprar pão');
    }

    public function test_audio_avisa_e_nao_abre_pendencia(): void
    {
        [, $instancia] = $this->instanciaConectada();

        $this->receberEvento([
            'audioMessage' => ['url' => 'https://x', 'mimetype' => 'audio/ogg'],
        ], 'audioMessage');

        $this->assertUltima('só entendo texto e foto');
        $this->assertEstado(WhatsappConversa::OCIOSO, $instancia);
    }

    public function test_foto_nova_substitui_a_pendencia_anterior_e_apaga_o_anexo_velho(): void
    {
        [$user, $instancia] = $this->instanciaConectada(['financeiro_ativo' => true]);

        $this->receberFoto(messageId: 'IMG-1');
        $this->receberFoto(messageId: 'IMG-2');

        $this->assertUltima('Peguei a última foto');
        // Uma pendência, um anexo: o da primeira foto foi apagado.
        $this->assertCount(1, Storage::disk('local')->files("whatsapp/pendentes/{$user->id}"));
        $this->assertEstado(WhatsappConversa::AGUARDANDO_TIPO_MIDIA, $instancia);
    }

    /**
     * Rede de segurança do staging: o fechamento normal já apaga o anexo, mas
     * um worker morto no meio deixaria a foto para sempre no disco.
     */
    public function test_limpeza_apaga_so_os_anexos_velhos_do_staging(): void
    {
        [$user] = $this->instanciaConectada(['financeiro_ativo' => true]);
        $disk = Storage::disk('local');

        $disk->put("whatsapp/pendentes/{$user->id}/antiga.jpg", 'bytes');
        $disk->put("whatsapp/pendentes/{$user->id}/recente.jpg", 'bytes');
        // Já promovido: não pode ser tocado pela varredura.
        $disk->put("receipts/{$user->id}/lancado.jpg", 'bytes');

        // O job olha o mtime do arquivo, não o relógio do Carbon: envelhecer
        // com travelTo() não teria efeito nenhum.
        touch($disk->path("whatsapp/pendentes/{$user->id}/antiga.jpg"), now()->subHours(30)->getTimestamp());

        (new \App\Jobs\LimparAnexosWhatsappOrfaos)->handle();

        $disk->assertMissing("whatsapp/pendentes/{$user->id}/antiga.jpg");
        $disk->assertExists("whatsapp/pendentes/{$user->id}/recente.jpg");
        $disk->assertExists("receipts/{$user->id}/lancado.jpg");
    }

    /**
     * ✅ e ❌ são prefixos de mensagem do bot: se também valessem como resposta,
     * o cinto de anti-loop no ingest descartaria a sua confirmação.
     */
    public function test_emojis_de_aceite_nao_colidem_com_os_prefixos_do_bot(): void
    {
        $prefixos = (array) config('whatsapp.conversa.prefixos_bot');

        foreach (['👍', '👌', '🆗', '💪', '🙌', '👎', '🚫', '🙅'] as $emoji) {
            $this->assertNotContains($emoji, $prefixos, "{$emoji} é aceito como resposta E abre mensagem do bot.");
        }
    }

    /**
     * O webhook da Evolution é público e roda fora de requisição autenticada:
     * sem checar is_active, desativar alguém tirava só o painel — o assistente
     * seguia respondendo, criando tarefas e gastando crédito de IA.
     */
    public function test_usuario_desativado_nao_tem_mais_assistente(): void
    {
        [$user] = $this->instanciaConectada();
        $user->update(['is_active' => false]);

        $this->mockRoteadorNuncaChamado();

        $this->receber('ligar pro dentista sexta');

        $this->assertSame(0, Task::count());
        $this->assertSame([], $this->enviadas, 'O bot respondeu para um usuário desativado.');
    }

    public function test_lembrete_de_suplemento_nao_vai_para_usuario_desativado(): void
    {
        [$user, $instancia] = $this->instanciaConectada();

        \App\Models\SaudeSuplemento::create([
            'user_id' => $user->id,
            'nome' => 'Creatina',
            'horario' => '07:00',
            'ativo' => true,
            'posicao' => 1,
        ]);

        $user->update(['is_active' => false]);

        (new \App\Jobs\EnviarLembretesSuplementos)->handle(app(WhatsappSender::class));

        $this->assertSame([], $this->enviadas);
        $this->assertSame(0, \App\Models\SaudeLembrete::where('user_id', $user->id)->count());
        $this->assertNotNull($instancia->fresh());
    }

    public function test_relatorio_diario_nao_enfileira_usuario_desativado(): void
    {
        Queue::fake();

        [$ativo] = $this->instanciaConectada(['relatorio_diario_ativo' => true]);

        $inativo = User::factory()->create(['is_active' => false]);
        WhatsappInstancia::create([
            'user_id' => $inativo->id,
            'instance_name' => 'inst-'.$inativo->id,
            'phone' => '5511888888888',
            'status' => 'conectado',
            'relatorio_diario_ativo' => true,
        ]);

        (new \App\Jobs\GerarRelatorioDiario)->handle();

        Queue::assertPushed(\App\Jobs\EnviarRelatorioWhatsapp::class, 1);
        Queue::assertPushed(
            \App\Jobs\EnviarRelatorioWhatsapp::class,
            fn (\App\Jobs\EnviarRelatorioWhatsapp $job) => $job->userId === $ativo->id
                && $job->tipo === 'diario',
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Entrega uma mensagem de texto pelo webhook real da Evolution.
     */
    private function receber(string $texto, ?string $messageId = null, mixed $momento = null): void
    {
        $this->receberEvento(['conversation' => $texto], 'conversation', $messageId, $momento);
    }

    private function receberFoto(?string $messageId = null, string $caption = ''): void
    {
        $this->receberEvento([
            'imageMessage' => ['url' => 'https://mmg.whatsapp.net/x.enc', 'caption' => $caption, 'mimetype' => 'image/jpeg'],
            'base64' => base64_encode('bytes-da-foto'),
        ], 'imageMessage', $messageId);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function receberEvento(array $message, string $tipo, ?string $messageId = null, mixed $momento = null): void
    {
        $instancia = WhatsappInstancia::sole();

        $this->postJson('/api/whatsapp/webhook/evolution?token=tok', [
            'event' => 'messages.upsert',
            'instance' => $instancia->instance_name,
            'data' => [
                'key' => [
                    'remoteJid' => $instancia->phone.'@s.whatsapp.net',
                    'fromMe' => true,
                    'id' => $messageId ?? 'MSG-'.uniqid(),
                ],
                'pushName' => 'Lucas',
                'messageTimestamp' => ($momento ?? now())->timestamp,
                'messageType' => $tipo,
                'message' => $message,
            ],
        ])->assertOk();
    }

    private function assertUltima(string $trecho): void
    {
        $this->assertNotEmpty($this->enviadas, "O bot não respondeu nada (esperava conter \"{$trecho}\").");
        $ultima = end($this->enviadas);
        $this->assertStringContainsString($trecho, $ultima, "Última resposta do bot:\n{$ultima}");
    }

    private function assertEstado(string $esperado, WhatsappInstancia $instancia): void
    {
        // A linha só nasce no primeiro uso: sem linha é o mesmo que ociosa.
        $this->assertSame(
            $esperado,
            WhatsappConversa::where('instancia_id', $instancia->id)->value('estado') ?? WhatsappConversa::OCIOSO,
        );
    }

    /**
     * @param  array<string, mixed>  ...$rotas  uma resposta por chamada, em ordem
     */
    private function mockRoteador(array ...$rotas): void
    {
        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $expectation = $ia->shouldReceive('rotearNota');
        foreach ($rotas as $rota) {
            $expectation->andReturn($rota);
        }
        $this->app->instance(SaudeNutricaoAI::class, $ia);
    }

    private function mockRoteadorNuncaChamado(): void
    {
        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldNotReceive('rotearNota');
        $this->app->instance(SaudeNutricaoAI::class, $ia);
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function mockVisao(array $analise): void
    {
        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('analisarFoto')->andReturn($analise);
        $this->app->instance(SaudeNutricaoAI::class, $ia);
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function mockReceipt(array $resultado): void
    {
        $parser = Mockery::mock(ReceiptParser::class);
        $parser->shouldReceive('parse')->andReturn($resultado);
        $this->app->instance(ReceiptParser::class, $parser);
    }

    /**
     * @param  array<string, mixed>  $interpretacao
     */
    private function mockInterpretacao(array $interpretacao): void
    {
        $ia = Mockery::mock(WhatsappConversaAI::class);
        $ia->shouldReceive('interpretarResposta')->andReturn($interpretacao);
        $this->app->instance(WhatsappConversaAI::class, $ia);
    }

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
            'gtd_ativo' => true,
            'calorias_foto_ativo' => true,
            'calorias_texto_ia' => true,
            'financeiro_ativo' => false,
        ]);

        return [$user, $instancia];
    }

    private function chatComigo(WhatsappInstancia $instancia): \App\Models\WhatsappChat
    {
        return \App\Models\WhatsappChat::firstOrCreate(
            ['instancia_id' => $instancia->id, 'chave' => $instancia->phone],
            ['phone' => $instancia->phone, 'is_group' => false],
        );
    }

    private function conta(User $user, string $nome, float $saldo): BankAccount
    {
        return BankAccount::create(['user_id' => $user->id, 'name' => $nome, 'balance' => $saldo]);
    }

    private function categoria(User $user, string $nome): FinanceCategory
    {
        return FinanceCategory::create([
            'user_id' => $user->id, 'name' => $nome, 'kind' => 'despesa', 'color' => '#64748b',
        ]);
    }

    private function cartao(User $user, string $nome, string $ultimos): CreditCard
    {
        return CreditCard::create([
            'user_id' => $user->id,
            'name' => $nome,
            'brand' => 'Mastercard',
            'last_four' => $ultimos,
            'limit' => 5000,
            'closing_day' => 20,
            'due_day' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function analiseFake(): array
    {
        return [
            'e_comida' => true,
            'nome' => 'Ovos mexidos com café com leite',
            'tipo' => 'cafe_da_manha',
            'itens' => [['nome' => 'Ovos', 'quantidade' => '2 unidades', 'calorias' => 180, 'proteinas_g' => 13.0]],
            'calorias' => 280,
            'proteinas_g' => 18.0,
            'carboidratos_g' => 9.0,
            'gorduras_g' => 19.0,
            'confianca' => 'alta',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function comprovanteFake(?int $categoriaId, array $overrides = [], ?string $cardLastFour = null): array
    {
        return [
            'document_type' => 'comprovante',
            'card_last_four' => $cardLastFour,
            'items' => [$this->itemFake($overrides + ['category_id' => $categoriaId])],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemFake(array $overrides = []): array
    {
        return $overrides + [
            'amount' => 187.40,
            'purchase_date' => '2026-08-11',
            'description' => 'Supermercado Zaffari',
            'payment_method' => 'pix',
            'installments_total' => null,
            'category_id' => null,
            'confidence' => 'alta',
        ];
    }
}
