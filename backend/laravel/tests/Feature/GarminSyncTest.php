<?php

namespace Tests\Feature;

use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
use App\Models\SaudeSono;
use App\Models\SaudeTreino;
use App\Models\SaudeTreinoSessao;
use App\Models\User;
use App\Services\Saude\GarminImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GarminSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://garmin-teste:8000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'garmin.ativo' => true,
            'garmin.base_url' => self::BASE,
            'garmin.token' => 'token-de-teste',
            'garmin.dias_janela' => 3,
        ]);
    }

    public function test_importa_corrida_como_cardio_e_musculacao_como_sessao(): void
    {
        $user = $this->usuarioGarmin();
        SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino A', 'tipo' => 'musculacao', 'posicao' => 1]);

        $this->fakeSidecar([
            $this->atividade(23868174577, 'running', '2026-08-05 19:19:47', duracaoSeg: 1353.96, distanciaM: 3153.42, calorias: 288, fcMedia: 143, fcMaxima: 164),
            $this->atividade(23696494213, 'strength_training', '2026-08-05 20:04:11', duracaoSeg: 671.07, calorias: 82),
        ]);

        $resultado = app(GarminImportService::class)->sincronizar();

        $this->assertSame(1, $resultado['cardio']);
        $this->assertSame(1, $resultado['treinos']);

        $cardio = SaudeCardioSessao::sole();
        $this->assertSame('corrida_rua', $cardio->modalidade);
        $this->assertSame('2026-08-05', $cardio->data->toDateString());
        $this->assertSame('19:19', substr((string) $cardio->horario, 0, 5));
        $this->assertSame(23, $cardio->duracao_min); // 1353,96s -> 22,6 min
        $this->assertSame('3.15', (string) $cardio->distancia_km);
        $this->assertSame(288, $cardio->calorias);
        $this->assertSame(143, $cardio->fc_media);
        $this->assertSame('garmin', $cardio->origem);

        $sessao = SaudeTreinoSessao::sole();
        $this->assertSame(11, $sessao->duracao_min);
        $this->assertSame(82, $sessao->calorias);
        $this->assertSame('garmin', $sessao->origem);
        $this->assertSame(23696494213, (int) $sessao->garmin_activity_id);
    }

    public function test_reimportacao_nao_duplica(): void
    {
        $this->usuarioGarmin();
        $this->fakeSidecar([
            $this->atividade(23868174577, 'running', '2026-08-05 19:19:47', duracaoSeg: 1353.96),
        ]);

        $import = app(GarminImportService::class);

        $primeira = $import->sincronizar();
        $segunda = $import->sincronizar();

        $this->assertSame(1, $primeira['cardio']);
        $this->assertSame(0, $segunda['cardio']);
        $this->assertSame(1, SaudeCardioSessao::count());
    }

    public function test_tipo_desconhecido_e_ignorado(): void
    {
        $this->usuarioGarmin();
        $this->fakeSidecar([
            $this->atividade(1, 'bouldering', '2026-08-05 19:00:00', duracaoSeg: 3600),
        ]);

        $resultado = app(GarminImportService::class)->sincronizar();

        $this->assertSame(1, $resultado['ignorados']);
        $this->assertSame(0, SaudeCardioSessao::count());
        $this->assertSame(0, SaudeTreinoSessao::count());
    }

    public function test_importa_resumo_diario_do_relogio(): void
    {
        $this->usuarioGarmin();
        $this->fakeSidecar([]);

        $resultado = app(GarminImportService::class)->sincronizar(1);

        $this->assertSame(1, $resultado['dias']);

        $dia = SaudeDiaGarmin::sole();
        $this->assertSame(414, $dia->calorias_ativas);
        $this->assertSame(2611, $dia->calorias_totais);
        $this->assertSame(8344, $dia->passos);
    }

    public function test_importa_a_noite_de_sono(): void
    {
        $this->usuarioGarmin();
        $this->fakeSidecar([]);

        $resultado = app(GarminImportService::class)->sincronizar(1);

        $this->assertSame(1, $resultado['sono']);

        $noite = SaudeSono::sole();
        $this->assertSame(312, $noite->duracao_min); // 18720s -> 5h12
        $this->assertSame(26, $noite->profundo_min);
        $this->assertSame(286, $noite->leve_min);
        $this->assertSame(0, $noite->rem_min);
        $this->assertSame(19, $noite->acordado_min);
        $this->assertSame(52, $noite->score);
        $this->assertSame('POOR', $noite->score_qualificador);
        $this->assertSame(1, $noite->despertares);
        $this->assertSame('00:35', substr((string) $noite->inicio, 0, 5));
        $this->assertSame('06:06', substr((string) $noite->fim, 0, 5));
        $this->assertSame('garmin', $noite->origem);
    }

    public function test_noite_sem_medicao_nao_vira_linha_em_branco(): void
    {
        $this->usuarioGarmin();

        Http::fake([
            self::BASE.'/atividades*' => Http::response(['atividades' => []]),
            self::BASE.'/dia*' => Http::response(['calorias_ativas' => 414]),
            // Relógio fora do pulso: o sidecar responde sem duração.
            self::BASE.'/sono*' => Http::response(['duracao_seg' => null]),
        ]);

        $resultado = app(GarminImportService::class)->sincronizar(1);

        $this->assertSame(0, $resultado['sono']);
        $this->assertSame(0, SaudeSono::count());
    }

    public function test_nao_sobrescreve_noite_lancada_a_mao(): void
    {
        $user = $this->usuarioGarmin();
        $hoje = now((string) config('saude.timezone'))->toDateString();

        SaudeSono::create([
            'user_id' => $user->id,
            'data' => $hoje,
            'duracao_min' => 480,
            'origem' => 'manual',
            'observacao' => 'dormi sem o relógio',
        ]);

        $this->fakeSidecar([]);

        $resultado = app(GarminImportService::class)->sincronizar(1);

        $this->assertSame(0, $resultado['sono']);

        $noite = SaudeSono::sole();
        $this->assertSame(480, $noite->duracao_min);
        $this->assertSame('manual', $noite->origem);
    }

    public function test_reimportar_a_mesma_noite_nao_duplica(): void
    {
        $this->usuarioGarmin();
        $this->fakeSidecar([]);

        $import = app(GarminImportService::class);
        $import->sincronizar(1);
        $import->sincronizar(1);

        $this->assertSame(1, SaudeSono::count());
    }

    public function test_erro_401_do_sidecar_vira_mensagem_em_portugues(): void
    {
        $this->usuarioGarmin();

        Http::fake([
            self::BASE.'/atividades*' => Http::response(['erro' => 'tokens_invalidos'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Os tokens do Garmin expiraram');

        app(GarminImportService::class)->sincronizar();
    }

    public function test_alterna_a_ficha_em_relacao_a_ultima_registrada(): void
    {
        $user = $this->usuarioGarmin();
        $a = SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino A', 'tipo' => 'musculacao', 'posicao' => 1]);
        $b = SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino B', 'tipo' => 'musculacao', 'posicao' => 2]);
        // Ficha de cardio não entra no rodízio.
        SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Cardio Longo Z2', 'tipo' => 'cardio', 'posicao' => 3]);

        SaudeTreinoSessao::create(['user_id' => $user->id, 'treino_id' => $a->id, 'data' => '2026-08-01']);

        $this->fakeSidecar([
            $this->atividade(99, 'strength_training', '2026-08-05 19:00:00', duracaoSeg: 2400),
        ]);

        app(GarminImportService::class)->sincronizar();

        $this->assertSame(
            $b->id,
            SaudeTreinoSessao::where('data', '2026-08-05')->value('treino_id'),
        );
    }

    public function test_auto_sync_importa_e_devolve_contagens(): void
    {
        $user = $this->usuarioGarmin();
        $this->fakeSidecar([
            $this->atividade(23876374258, 'treadmill_running', '2026-08-06 12:23:57', duracaoSeg: 475.5),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', true)
            ->assertJsonPath('cardio', 1);

        $this->assertSame(1, SaudeCardioSessao::count());
    }

    public function test_auto_sync_respeita_a_trava_de_dez_minutos(): void
    {
        $user = $this->usuarioGarmin();
        $token = $this->bearerTokenFor($user);
        $this->fakeSidecar([]);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', true);

        $chamadasAposPrimeira = count(Http::recorded());

        // Segunda chamada dentro da janela: nada de HTTP novo para o sidecar.
        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', false)
            ->assertJsonPath('motivo', 'recente');

        $this->assertCount($chamadasAposPrimeira, Http::recorded());
    }

    public function test_auto_sync_nao_configurado_e_silencioso(): void
    {
        config(['garmin.ativo' => false]);
        $user = $this->usuarioGarmin();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', false)
            ->assertJsonPath('motivo', 'nao_configurado');
    }

    public function test_auto_sync_com_sidecar_quebrado_nao_estoura(): void
    {
        $user = $this->usuarioGarmin();

        Http::fake([
            self::BASE.'/atividades*' => Http::response(['erro' => 'tokens_invalidos'], 401),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', false)
            ->assertJsonPath('motivo', 'erro');
    }

    /** @param  list<array<string, mixed>>  $atividades */
    private function fakeSidecar(array $atividades): void
    {
        Http::fake([
            self::BASE.'/atividades*' => Http::response(['atividades' => $atividades]),
            self::BASE.'/dia*' => Http::response([
                'passos' => 8344,
                'calorias_ativas' => 414,
                'calorias_totais' => 2611,
                'fc_repouso' => 48,
                'minutos_intensidade' => 52,
            ]),
            self::BASE.'/sono*' => Http::response([
                'duracao_seg' => 18720,
                'profundo_seg' => 1560,
                'leve_seg' => 17160,
                'rem_seg' => 0,
                'acordado_seg' => 1140,
                'cochilo_seg' => 0,
                'score' => 52,
                'score_qualificador' => 'POOR',
                'despertares' => 1,
                'estresse_medio' => 17.0,
                'hrv_medio' => 69.0,
                'inicio_local' => '00:35',
                'fim_local' => '06:06',
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function atividade(
        int $id,
        string $tipo,
        string $inicio,
        float $duracaoSeg = 600,
        ?float $distanciaM = null,
        ?int $calorias = null,
        ?int $fcMedia = null,
        ?int $fcMaxima = null,
    ): array {
        return array_filter([
            'id' => $id,
            'nome' => 'Atividade',
            'tipo' => $tipo,
            'inicio_local' => $inicio,
            'duracao_seg' => $duracaoSeg,
            'distancia_m' => $distanciaM,
            'calorias' => $calorias,
            'fc_media' => $fcMedia,
            'fc_maxima' => $fcMaxima,
        ], fn ($valor) => $valor !== null);
    }

    private function usuarioGarmin(): User
    {
        $user = User::factory()->create(['email' => 'garmin@teste.com']);
        config(['garmin.user_email' => 'garmin@teste.com']);

        return $user;
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }

    // Um sidecar = uma conta Garmin. Sem estas guardas, qualquer usuário do
    // painel que abrisse a tela de Saúde disparava o auto-sync e gravava
    // cardio, sono e saude_metas na conta do dono.

    public function test_status_do_garmin_nao_vaza_para_outro_usuario(): void
    {
        $this->usuarioGarmin();
        $outro = User::factory()->create(['email' => 'familiar@teste.com']);

        Http::fake();

        $this->withHeader('Authorization', $this->bearerTokenFor($outro))
            ->getJson('/api/saude/garmin/status')
            ->assertOk()
            ->assertJsonPath('configurado', false)
            ->assertJsonPath('status.erro', 'nao_configurado');

        // Nem chega a bater no sidecar.
        Http::assertNothingSent();
    }

    public function test_sincronizacao_manual_e_bloqueada_para_outro_usuario(): void
    {
        $this->usuarioGarmin();
        $outro = User::factory()->create(['email' => 'familiar@teste.com']);

        Http::fake();

        $this->withHeader('Authorization', $this->bearerTokenFor($outro))
            ->postJson('/api/saude/garmin/sincronizar')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_auto_sync_de_outro_usuario_nao_importa_nada(): void
    {
        $dono = $this->usuarioGarmin();
        $outro = User::factory()->create(['email' => 'familiar@teste.com']);

        Http::fake();

        $this->withHeader('Authorization', $this->bearerTokenFor($outro))
            ->postJson('/api/saude/garmin/sincronizar-auto')
            ->assertOk()
            ->assertJsonPath('executado', false)
            ->assertJsonPath('motivo', 'nao_configurado');

        Http::assertNothingSent();

        $this->assertSame(0, SaudeCardioSessao::where('user_id', $dono->id)->count());
        $this->assertSame(0, SaudeSono::where('user_id', $dono->id)->count());
        $this->assertSame(0, SaudeDiaGarmin::where('user_id', $dono->id)->count());
    }

    public function test_dono_continua_sincronizando_pela_rota(): void
    {
        $dono = $this->usuarioGarmin();

        $this->fakeSidecar([
            $this->atividade(23868174577, 'running', '2026-08-05 19:19:47', duracaoSeg: 1353.96),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($dono))
            ->postJson('/api/saude/garmin/sincronizar')
            ->assertOk()
            ->assertJsonPath('cardio', 1);

        $this->assertSame(1, SaudeCardioSessao::where('user_id', $dono->id)->count());
    }
}
