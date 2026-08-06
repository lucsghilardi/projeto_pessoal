<?php

namespace Tests\Feature;

use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
use App\Models\SaudeTreino;
use App\Models\SaudeTreinoSessao;
use App\Models\User;
use App\Services\Saude\GarminImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
