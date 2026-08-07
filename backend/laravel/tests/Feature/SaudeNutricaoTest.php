<?php

namespace Tests\Feature;

use App\Jobs\ProcessarInboxGtd;
use App\Jobs\ProcessarRefeicaoWhatsapp;
use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
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
use Illuminate\Http\UploadedFile;
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
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(35)->toDateString(),
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

    public function test_gasto_dinamico_desligado_nao_altera_metas(): void
    {
        // Guarda de regressão: os números de test_tmb_tdee_e_metas_derivadas
        // têm que sobreviver mesmo com cardio registrado no dia.
        $user = $this->usuarioComPerfil();
        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => now()->toDateString(),
            'modalidade' => 'corrida_rua',
            'duracao_min' => 45,
            'calorias' => 500,
            'origem' => 'manual',
        ]);

        $service = app(SaudeNutricaoService::class);
        $metas = $service->resumoDia($user, now()->toDateString())['metas'];

        $this->assertFalse($metas['gasto_dinamico']);
        $this->assertSame(2888, $metas['tdee']);
        $this->assertSame(2888 - 500, $metas['calorias']);
        $this->assertSame(0, $metas['gasto_exercicio']);
    }

    public function test_gasto_dinamico_soma_o_exercicio_ao_tdee(): void
    {
        $user = $this->usuarioComPerfil(['gasto_dinamico' => true]);
        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => now()->toDateString(),
            'modalidade' => 'corrida_rua',
            'duracao_min' => 45,
            'calorias' => 420,
            'origem' => 'manual',
        ]);

        $metas = app(SaudeNutricaoService::class)
            ->resumoDia($user, now()->toDateString())['metas'];

        // TMB 1863 × 1,2 = 2236, + 420 gastos.
        $this->assertTrue($metas['gasto_dinamico']);
        $this->assertSame(420, $metas['gasto_exercicio']);
        $this->assertSame(2236 + 420, $metas['tdee']);
    }

    public function test_calorias_ativas_do_relogio_vencem_a_soma_das_sessoes(): void
    {
        $user = $this->usuarioComPerfil(['gasto_dinamico' => true]);
        $hoje = now()->toDateString();

        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => $hoje,
            'modalidade' => 'corrida_rua',
            'duracao_min' => 22,
            'calorias' => 288,
            'origem' => 'garmin',
        ]);
        // O dia do relógio cobre também os passos fora de atividade registrada.
        SaudeDiaGarmin::create([
            'user_id' => $user->id,
            'data' => $hoje,
            'passos' => 8344,
            'calorias_ativas' => 414,
            'calorias_totais' => 2611,
        ]);

        $metas = app(SaudeNutricaoService::class)->resumoDia($user, $hoje)['metas'];

        $this->assertSame(414, $metas['gasto_exercicio']);
    }

    public function test_calorias_estimadas_por_met_quando_nao_ha_medicao(): void
    {
        $user = $this->usuarioComPerfil(['gasto_dinamico' => true]);
        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => now()->toDateString(),
            'modalidade' => 'caminhada',
            'duracao_min' => 60,
            'origem' => 'manual',
        ]);

        $metas = app(SaudeNutricaoService::class)
            ->resumoDia($user, now()->toDateString())['metas'];

        // (3,5 - 1) × 3,5 × 92 kg / 200 × 60 min = 241,5
        $this->assertSame(242, $metas['gasto_exercicio']);
    }

    public function test_projecao_usa_a_media_e_nao_o_gasto_de_hoje(): void
    {
        $user = $this->usuarioComPerfil(['gasto_dinamico' => true]);
        // Um único treino pesado em 28 dias: a projeção tem que diluí-lo.
        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => now()->toDateString(),
            'modalidade' => 'corrida_rua',
            'duracao_min' => 60,
            'calorias' => 700,
            'origem' => 'manual',
        ]);

        $service = app(SaudeNutricaoService::class);

        $tdeeHoje = $service->resumoDia($user, now()->toDateString())['metas']['tdee'];
        $ritmoProjecao = $service->projecao($user)['ritmo_plano_kg_semana'];

        $this->assertSame(2236 + 700, $tdeeHoje);

        // 700 kcal diluídas em 28 dias = 25/dia; TDEE da projeção ≈ 2261, com
        // déficit padrão de 500 → 500 × 7 / 7700 ≈ 0,45 kg/semana.
        $this->assertSame(0.45, $ritmoProjecao);
    }

    /** @param  array<string, mixed>  $meta */
    private function usuarioComPerfil(array $meta = []): User
    {
        $user = User::factory()->create();

        SaudeMeta::create($meta + [
            'user_id' => $user->id,
            'peso_meta_kg' => 82,
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(35)->toDateString(),
            'nivel_atividade' => 'moderado',
        ]);
        SaudePeso::create(['user_id' => $user->id, 'data' => now()->toDateString(), 'peso_kg' => 92]);

        return $user;
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
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(35)->toDateString(),
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
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(35)->toDateString(),
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

    // ============================================================
    // Painel — estimativa da IA no formulário
    // ============================================================

    public function test_analisar_estima_pela_descricao_sem_gravar_nada(): void
    {
        $token = $this->bearerTokenFor(User::factory()->create());

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('analisarTexto')
            ->once()
            ->with('2 ovos mexidos e café com leite')
            ->andReturn($this->analiseFake());
        $this->app->instance(SaudeNutricaoAI::class, $ia);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/refeicoes/analisar', [
                'descricao' => '2 ovos mexidos e café com leite',
            ])
            ->assertOk()
            ->assertJsonPath('calorias', 620)
            ->assertJsonPath('confianca', 'alta')
            ->assertJsonPath('itens.0.nome', 'Frango');

        // Quem grava é o usuário, ao confirmar.
        $this->assertSame(0, SaudeRefeicao::count());
    }

    public function test_analisar_usa_a_foto_e_passa_a_descricao_como_complemento(): void
    {
        $token = $this->bearerTokenFor(User::factory()->create());

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldReceive('analisarFoto')
            ->once()
            ->withArgs(fn ($binario, $mime, $caption) => $binario !== ''
                && str_starts_with((string) $mime, 'image/')
                && $caption === 'fritei no óleo')
            ->andReturn($this->analiseFake());
        $this->app->instance(SaudeNutricaoAI::class, $ia);

        $this->withHeader('Authorization', $token)
            ->post('/api/saude/refeicoes/analisar', [
                'descricao' => 'fritei no óleo',
                'foto' => $this->fotoFake('prato.png'),
            ])
            ->assertOk()
            ->assertJsonPath('e_comida', true);
    }

    public function test_analisar_sem_descricao_nem_foto_e_recusado(): void
    {
        $token = $this->bearerTokenFor(User::factory()->create());

        $ia = Mockery::mock(SaudeNutricaoAI::class);
        $ia->shouldNotReceive('analisarTexto');
        $ia->shouldNotReceive('analisarFoto');
        $this->app->instance(SaudeNutricaoAI::class, $ia);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/refeicoes/analisar', [])
            ->assertStatus(422);
    }

    public function test_confirmar_estimativa_grava_procedencia_itens_e_foto(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $resposta = $this->withHeader('Authorization', $token)
            ->post('/api/saude/refeicoes', [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'Frango grelhado com arroz',
                'tipo' => 'almoco',
                'calorias' => 620,
                'proteinas_g' => 45,
                'origem' => 'painel_ia',
                'confianca' => 'alta',
                'itens' => [
                    ['nome' => 'Frango', 'quantidade' => '1 filé', 'calorias' => 220, 'proteinas_g' => 40],
                ],
                'foto' => $this->fotoFake('prato.png'),
            ])
            ->assertCreated()
            ->assertJsonPath('origem', 'painel_ia')
            ->assertJsonPath('confianca', 'alta')
            ->assertJsonPath('itens.0.nome', 'Frango');

        $refeicao = SaudeRefeicao::findOrFail($resposta->json('id'));
        $this->assertStringStartsWith("saude/refeicoes/{$user->id}/", (string) $refeicao->foto_path);
        Storage::disk('local')->assertExists($refeicao->foto_path);

        $this->withHeader('Authorization', $token)
            ->get("/api/saude/refeicoes/{$refeicao->id}/foto")
            ->assertOk();

        // Apagar a refeição leva a foto junto.
        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/saude/refeicoes/{$refeicao->id}")
            ->assertOk();
        Storage::disk('local')->assertMissing($refeicao->foto_path);
    }

    public function test_editar_no_painel_nao_apaga_a_procedencia_do_whatsapp(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $refeicao = SaudeRefeicao::create([
            'user_id' => $user->id,
            'data' => '2026-08-06',
            'horario' => '12:30:00',
            'nome' => 'PF com bife',
            'tipo' => 'almoco',
            'itens' => [['nome' => 'Bife', 'quantidade' => '1 unidade', 'calorias' => 300]],
            'calorias' => 750,
            'proteinas_g' => 42,
            'confianca' => 'media',
            'origem' => 'whatsapp_foto',
        ]);

        // O formulário manda só os campos nutricionais ao corrigir a estimativa.
        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/refeicoes/{$refeicao->id}", [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'PF com bife (meia porção)',
                'calorias' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('calorias', 500)
            ->assertJsonPath('origem', 'whatsapp_foto')
            ->assertJsonPath('confianca', 'media')
            ->assertJsonPath('itens.0.nome', 'Bife');
    }

    public function test_editar_troca_a_foto_e_apaga_a_anterior(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $antiga = "saude/refeicoes/{$user->id}/antiga.jpg";
        Storage::disk('local')->put($antiga, 'fake-jpeg-bytes');

        $refeicao = SaudeRefeicao::create([
            'user_id' => $user->id,
            'data' => '2026-08-06',
            'horario' => '12:30:00',
            'nome' => 'PF com bife',
            'calorias' => 750,
            'proteinas_g' => 42,
            'origem' => 'painel_ia',
            'foto_path' => $antiga,
        ]);

        $this->withHeader('Authorization', $token)
            ->post("/api/saude/refeicoes/{$refeicao->id}", [
                '_method' => 'PUT',
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'PF com bife',
                'calorias' => 750,
                'foto' => $this->fotoFake('nova.png'),
            ])
            ->assertOk();

        $nova = (string) $refeicao->fresh()->foto_path;
        $this->assertNotSame($antiga, $nova);
        Storage::disk('local')->assertMissing($antiga);
        Storage::disk('local')->assertExists($nova);

        // remover_foto sem arquivo novo desanexa e limpa o disco.
        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/refeicoes/{$refeicao->id}", [
                'data' => '2026-08-06',
                'horario' => '12:30',
                'nome' => 'PF com bife',
                'calorias' => 750,
                'remover_foto' => true,
            ])
            ->assertOk();

        $this->assertNull($refeicao->fresh()->foto_path);
        Storage::disk('local')->assertMissing($nova);
    }

    public function test_overview_soma_o_dia_e_calcula_restante(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        SaudeMeta::create([
            'user_id' => $user->id,
            'altura_cm' => 178,
            'sexo' => 'M',
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(35)->toDateString(),
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
     * PNG 1x1 de verdade: UploadedFile::fake()->image() depende da extensão GD,
     * que não está instalada na imagem do backend.
     */
    private function fotoFake(string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'foto').'.png';
        file_put_contents($caminho, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        return new UploadedFile($caminho, $nome, 'image/png', null, true);
    }

    /**
     * Retorno já normalizado do SaudeNutricaoAI.
     *
     * @return array<string, mixed>
     */
    private function analiseFake(): array
    {
        return [
            'e_comida' => true,
            'nome' => 'Frango grelhado com arroz',
            'tipo' => 'almoco',
            'itens' => [['nome' => 'Frango', 'quantidade' => '1 filé', 'calorias' => 220, 'proteinas_g' => 40.0]],
            'calorias' => 620,
            'proteinas_g' => 45.0,
            'carboidratos_g' => 55.0,
            'gorduras_g' => 18.0,
            'confianca' => 'alta',
        ];
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
