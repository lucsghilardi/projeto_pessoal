<?php

namespace Tests\Feature;

use App\Jobs\ProcessarInboxGtd;
use App\Jobs\ProcessarRefeicaoWhatsapp;
use App\Models\SaudeMeta;
use App\Models\SaudePeso;
use App\Models\SaudeRefeicao;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Saude\SaudeNutricaoService;
use App\Services\Whatsapp\WhatsappAnaliseService;
use App\Services\Whatsapp\WhatsappIngestService;
use App\Services\Whatsapp\WhatsappSender;
use App\Services\Whatsapp\WhatsappTaskBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SaudeNutricaoTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================
    // Cálculos (TMB / TDEE / metas)
    // ============================================================

    public function test_tmb_tdee_e_metas_derivadas(): void
    {
        $user = User::factory()->create();
        SaudeMeta::create([
            'user_id' => $user->id,
            'peso_meta_kg' => 82,
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now()->subYears(35)->toDateString(),
            'nivel_atividade' => 'moderado',
        ]);
        SaudePeso::create(['user_id' => $user->id, 'data' => now()->toDateString(), 'peso_kg' => 92]);

        $service = app(SaudeNutricaoService::class);

        // Mifflin-St Jeor: 10*92 + 6.25*178 - 5*35 + 5 = 1862.5
        $this->assertSame(1863, $service->tmb('M', 35, 178, 92.0));
        $this->assertSame(2888, $service->tdee(1863, 'moderado'));

        $perfil = $service->perfil($user);
        $this->assertTrue($perfil['completo']);

        $metas = $service->metas($perfil);
        // Sem data alvo: déficit padrão de 500 kcal.
        $this->assertSame(2888 - 500, $metas['calorias']);
        // Proteína: 1.8 g/kg do peso atual.
        $this->assertSame(166, $metas['proteinas_g']);
    }

    public function test_deficit_respeita_teto_e_piso(): void
    {
        // Meta agressiva demais: 10 kg em 10 dias → trava em 25% do TDEE.
        $user = User::factory()->create();
        SaudeMeta::create([
            'user_id' => $user->id,
            'peso_meta_kg' => 82,
            'data_alvo' => now()->addDays(10)->toDateString(),
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now()->subYears(35)->toDateString(),
            'nivel_atividade' => 'moderado',
        ]);
        SaudePeso::create(['user_id' => $user->id, 'data' => now()->toDateString(), 'peso_kg' => 92]);

        $service = app(SaudeNutricaoService::class);
        $metas = $service->metas($service->perfil($user));
        $this->assertSame(2888 - 722, $metas['calorias']); // 25% de 2888 = 722

        // Perfil pequeno: o piso de calorias por sexo prevalece.
        $userF = User::factory()->create();
        SaudeMeta::create([
            'user_id' => $userF->id,
            'peso_meta_kg' => 45,
            'data_alvo' => now()->addDays(30)->toDateString(),
            'altura_cm' => 150,
            'sexo' => 'F',
            'data_nascimento' => now()->subYears(50)->toDateString(),
            'nivel_atividade' => 'sedentario',
        ]);
        SaudePeso::create(['user_id' => $userF->id, 'data' => now()->toDateString(), 'peso_kg' => 50]);

        $metasF = $service->metas($service->perfil($userF));
        $this->assertSame(1200, $metasF['calorias']);
    }

    public function test_override_manual_tem_prioridade(): void
    {
        $user = User::factory()->create();
        SaudeMeta::create([
            'user_id' => $user->id,
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now()->subYears(35)->toDateString(),
            'nivel_atividade' => 'moderado',
            'calorias_alvo' => 2000,
            'proteinas_alvo_g' => 150,
        ]);
        SaudePeso::create(['user_id' => $user->id, 'data' => now()->toDateString(), 'peso_kg' => 92]);

        $service = app(SaudeNutricaoService::class);
        $metas = $service->metas($service->perfil($user));

        $this->assertSame(2000, $metas['calorias']);
        $this->assertSame(150, $metas['proteinas_g']);
    }

    // ============================================================
    // API
    // ============================================================

    public function test_crud_de_refeicoes_com_ownership(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $resposta = $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/refeicoes', [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'PF com bife',
                'tipo' => 'almoco',
                'calorias' => 750,
                'proteinas_g' => 42,
            ])
            ->assertCreated()
            ->assertJsonPath('origem', 'manual');

        $id = (int) $resposta->json('id');

        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/refeicoes/{$id}", [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'PF com bife (meia porção)',
                'calorias' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('calorias', 500);

        // Outro usuário não enxerga nem altera.
        $intruso = $this->bearerTokenFor(User::factory()->create());
        $this->withHeader('Authorization', $intruso)
            ->putJson("/api/saude/refeicoes/{$id}", [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'Hackeado',
                'calorias' => 1,
            ])
            ->assertForbidden();

        // Re-login: o guard memoiza o último usuário logado no mesmo teste.
        $token = $this->bearerTokenFor($user);
        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/saude/refeicoes/{$id}")
            ->assertOk();

        $this->assertSame(0, SaudeRefeicao::count());
    }

    public function test_overview_soma_o_dia_e_calcula_restante(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        SaudeMeta::create([
            'user_id' => $user->id,
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now()->subYears(35)->toDateString(),
            'nivel_atividade' => 'moderado',
            'calorias_alvo' => 2000,
            'proteinas_alvo_g' => 150,
        ]);
        SaudePeso::create(['user_id' => $user->id, 'data' => '2026-08-06', 'peso_kg' => 92]);

        SaudeRefeicao::create([
            'user_id' => $user->id, 'data' => '2026-08-06', 'horario' => '08:00',
            'nome' => 'Café', 'calorias' => 350, 'proteinas_g' => 20,
        ]);
        SaudeRefeicao::create([
            'user_id' => $user->id, 'data' => '2026-08-06', 'horario' => '12:30',
            'nome' => 'Almoço', 'calorias' => 700, 'proteinas_g' => 45.5,
        ]);
        // Outro dia não entra na soma.
        SaudeRefeicao::create([
            'user_id' => $user->id, 'data' => '2026-08-05', 'horario' => '12:30',
            'nome' => 'Ontem', 'calorias' => 999, 'proteinas_g' => 10,
        ]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/saude/nutricao?data=2026-08-06')
            ->assertOk()
            ->assertJsonPath('consumido.calorias', 1050)
            ->assertJsonPath('consumido.proteinas_g', 65.5)
            ->assertJsonPath('restante.calorias', 950)
            ->assertJsonPath('metas.calorias', 2000)
            ->assertJsonCount(2, 'refeicoes')
            ->assertJsonCount(14, 'historico');
    }

    // ============================================================
    // WhatsApp — foto
    // ============================================================

    public function test_ingest_salva_foto_e_despacha_job(): void
    {
        Storage::fake('local');
        Queue::fake();

        [$user, $instancia] = $this->instanciaConectada();

        $norm = [
            'phone' => $instancia->phone,
            'messageId' => 'MSG-FOTO-1',
            'fromMe' => true,
            'chatLid' => '',
            'senderName' => 'Lucas',
            'isGroup' => false,
            'instanceName' => $instancia->instance_name,
            'momment' => now()->getTimestampMs(),
            'status' => 'SENT',
            'image' => [
                'imageUrl' => 'https://mmg.whatsapp.net/x.enc',
                'caption' => 'almoço',
                'mimeType' => 'image/jpeg',
                'base64' => base64_encode('fake-jpeg-bytes'),
            ],
        ];

        app(WhatsappIngestService::class)->processarEvento($norm, $instancia);

        $mensagem = WhatsappMensagem::where('message_id', 'MSG-FOTO-1')->firstOrFail();
        $this->assertSame('image', $mensagem->tipo);
        // O base64 nunca é persistido no raw_payload.
        $this->assertArrayNotHasKey('base64', $mensagem->raw_payload['image']);

        $arquivos = Storage::disk('local')->files("saude/refeicoes/{$user->id}");
        $this->assertCount(1, $arquivos);

        Queue::assertPushed(ProcessarRefeicaoWhatsapp::class, 1);
    }

    public function test_job_de_foto_registra_refeicao_e_responde_uma_vez(): void
    {
        Storage::fake('local');

        [$user, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemImagem($instancia);

        $path = "saude/refeicoes/{$user->id}/foto.jpg";
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('analisarFoto')->once()->andReturn([
            'e_comida' => true,
            'nome' => 'Frango grelhado com arroz',
            'tipo' => 'almoco',
            'itens' => [['nome' => 'Frango', 'quantidade' => '1 filé', 'calorias' => 220, 'proteinas_g' => 40.0]],
            'calorias' => 620,
            'proteinas_g' => 45.0,
            'carboidratos_g' => 55.0,
            'gorduras_g' => 18.0,
            'confianca' => 'alta',
        ]);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')
            ->once()
            ->withArgs(fn ($inst, string $texto) => str_contains($texto, 'kcal') && str_contains($texto, 'Frango'))
            ->andReturn(true);

        $job = new ProcessarRefeicaoWhatsapp($mensagem->id, $path);
        $job->handle($ia, app(SaudeNutricaoService::class), $sender);

        $refeicao = SaudeRefeicao::where('whatsapp_mensagem_id', $mensagem->id)->firstOrFail();
        $this->assertSame('whatsapp_foto', $refeicao->origem);
        $this->assertSame(620, $refeicao->calorias);
        $this->assertSame($path, $refeicao->foto_path);

        // Reexecutar (retry) não duplica nem responde de novo.
        $job->handle($ia, app(SaudeNutricaoService::class), $sender);
        $this->assertSame(1, SaudeRefeicao::count());
    }

    public function test_job_de_foto_sem_comida_nao_registra(): void
    {
        Storage::fake('local');

        [$user, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemImagem($instancia);

        $path = "saude/refeicoes/{$user->id}/foto.jpg";
        Storage::disk('local')->put($path, 'fake-jpeg-bytes');

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('analisarFoto')->once()->andReturn([
            'e_comida' => false, 'nome' => 'Paisagem', 'tipo' => 'outro', 'itens' => [],
            'calorias' => 0, 'proteinas_g' => 0.0, 'carboidratos_g' => null, 'gorduras_g' => null,
            'confianca' => 'baixa',
        ]);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')
            ->once()
            ->withArgs(fn ($inst, string $texto) => str_contains($texto, 'Não identifiquei comida'))
            ->andReturn(true);

        (new ProcessarRefeicaoWhatsapp($mensagem->id, $path))
            ->handle($ia, app(SaudeNutricaoService::class), $sender);

        $this->assertSame(0, SaudeRefeicao::count());
        Storage::disk('local')->assertMissing($path);
    }

    // ============================================================
    // WhatsApp — texto (roteador IA-decide no GTD)
    // ============================================================

    public function test_gtd_com_ia_decide_registra_refeicao_de_texto(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemTexto($instancia, '2 ovos mexidos e café preto');

        $analise = Mockery::mock(WhatsappAnaliseService::class);
        $analise->shouldNotReceive('extrairTarefaDeNota');

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('rotearNota')->once()->andReturn([
            'tipo' => 'refeicao',
            'refeicao' => [
                'e_comida' => true, 'nome' => 'Ovos mexidos com café', 'tipo' => 'cafe_da_manha',
                'itens' => [], 'calorias' => 210, 'proteinas_g' => 13.0,
                'carboidratos_g' => 2.0, 'gorduras_g' => 15.0, 'confianca' => 'media',
            ],
            'tarefa' => null,
        ]);

        $bridge = Mockery::mock(WhatsappTaskBridge::class);
        $bridge->shouldNotReceive('criarTarefa');

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')->once()->andReturn(true);

        (new ProcessarInboxGtd($mensagem->id))
            ->handle($analise, $ia, app(SaudeNutricaoService::class), $bridge, $sender);

        $refeicao = SaudeRefeicao::where('whatsapp_mensagem_id', $mensagem->id)->firstOrFail();
        $this->assertSame('whatsapp_texto', $refeicao->origem);
        $this->assertSame(0, Task::count());
    }

    public function test_gtd_com_ia_decide_mantem_fluxo_de_tarefa(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $mensagem = $this->mensagemTexto($instancia, 'Renovar o domínio do site');

        $analise = Mockery::mock(WhatsappAnaliseService::class);
        $analise->shouldNotReceive('extrairTarefaDeNota');

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('rotearNota')->once()->andReturn([
            'tipo' => 'tarefa',
            'refeicao' => null,
            'tarefa' => [
                'titulo' => 'Renovar o domínio do site',
                'descricao' => null,
                'prioridade' => 'medium',
                'due_date' => null,
            ],
        ]);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')
            ->once()
            ->withArgs(fn ($inst, string $texto) => str_starts_with($texto, '✅'))
            ->andReturn(true);

        (new ProcessarInboxGtd($mensagem->id))
            ->handle($analise, $ia, app(SaudeNutricaoService::class), app(WhatsappTaskBridge::class), $sender);

        $this->assertSame(1, Task::count());
        $this->assertSame(0, SaudeRefeicao::count());
    }

    public function test_gtd_sem_ia_decide_usa_fluxo_original(): void
    {
        [, $instancia] = $this->instanciaConectada();
        $instancia->update(['calorias_texto_ia' => false]);
        $mensagem = $this->mensagemTexto($instancia, 'Comprar ovos no mercado');

        $analise = Mockery::mock(WhatsappAnaliseService::class);
        $analise->shouldReceive('extrairTarefaDeNota')->once()->andReturn([
            'titulo' => 'Comprar ovos no mercado',
            'descricao' => null,
            'prioridade' => 'medium',
            'due_date' => null,
        ]);

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldNotReceive('rotearNota');

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')->once()->andReturn(true);

        (new ProcessarInboxGtd($mensagem->id))
            ->handle($analise, $ia, app(SaudeNutricaoService::class), app(WhatsappTaskBridge::class), $sender);

        $this->assertSame(1, Task::count());
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }

    /**
     * @return array{0: User, 1: WhatsappInstancia}
     */
    private function instanciaConectada(): array
    {
        $user = User::factory()->create();
        $instancia = WhatsappInstancia::create([
            'user_id' => $user->id,
            'instance_name' => 'inst-'.$user->id,
            'phone' => '5511999999999',
            'status' => 'conectado',
            'gtd_ativo' => true,
            'calorias_foto_ativo' => true,
            'calorias_texto_ia' => true,
        ]);

        return [$user, $instancia];
    }

    private function chatComigo(WhatsappInstancia $instancia): WhatsappChat
    {
        return WhatsappChat::firstOrCreate(
            ['instancia_id' => $instancia->id, 'chave' => $instancia->phone],
            ['phone' => $instancia->phone, 'is_group' => false],
        );
    }

    private function mensagemImagem(WhatsappInstancia $instancia): WhatsappMensagem
    {
        return WhatsappMensagem::create([
            'chat_id' => $this->chatComigo($instancia)->id,
            'instancia_id' => $instancia->id,
            'message_id' => 'IMG-'.uniqid(),
            'phone' => $instancia->phone,
            'from_me' => true,
            'tipo' => 'image',
            'texto' => '[Imagem] almoço',
            'caption' => 'almoço',
            'media_mime' => 'image/jpeg',
            'momment' => now()->getTimestampMs(),
        ]);
    }

    private function mensagemTexto(WhatsappInstancia $instancia, string $texto): WhatsappMensagem
    {
        return WhatsappMensagem::create([
            'chat_id' => $this->chatComigo($instancia)->id,
            'instancia_id' => $instancia->id,
            'message_id' => 'TXT-'.uniqid(),
            'phone' => $instancia->phone,
            'from_me' => true,
            'tipo' => 'text',
            'texto' => $texto,
            'momment' => now()->getTimestampMs(),
        ]);
    }
}
