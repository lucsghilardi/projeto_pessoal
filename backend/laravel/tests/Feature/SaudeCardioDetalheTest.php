<?php

namespace Tests\Feature;

use App\Models\SaudeCardioAnalise;
use App\Models\SaudeCardioDetalhe;
use App\Models\SaudeCardioSessao;
use App\Models\SaudeMeta;
use App\Models\SaudePeso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaudeCardioDetalheTest extends TestCase
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
            'services.anthropic.key' => 'chave-de-teste',
            'saude.cardio.model' => 'claude-sonnet-5',
        ]);
    }

    public function test_busca_o_detalhe_no_garmin_e_grava_splits_e_zonas(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")
            ->assertOk();

        $detalhe = SaudeCardioDetalhe::sole();
        $this->assertSame($sessao->id, $detalhe->cardio_sessao_id);
        $this->assertCount(6, $detalhe->splits);
        $this->assertCount(5, $detalhe->zonas_fc);
        $this->assertSame(155, $detalhe->cadencia_media);
        $this->assertSame(45, $detalhe->elevacao_ganho_m);
        // Duração e distância originais: a sessão só guarda minuto inteiro.
        $this->assertSame(2375, $detalhe->duracao_seg);
        $this->assertSame(5594, $detalhe->distancia_m);
    }

    public function test_reimportar_o_detalhe_atualiza_em_vez_de_duplicar(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();
        $token = $this->bearerTokenFor($user);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")->assertOk();
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")->assertOk();

        $this->assertSame(1, SaudeCardioDetalhe::count());
    }

    public function test_show_usa_a_duracao_exata_para_o_ritmo(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);

        // Sem detalhe: o ritmo sai do minuto arredondado (40 min / 5,59 km).
        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertSame('sessao', $resposta->json('metricas.fonte'));
        $this->assertSame(429, $resposta->json('metricas.pace_seg_km'));

        $this->fakeGarmin();
        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")->assertOk();

        // Com detalhe: 2375 s / 5,594 km = 425 s/km.
        $comDetalhe = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertSame('garmin', $comDetalhe->json('metricas.fonte'));
        $this->assertSame(425, $comDetalhe->json('metricas.pace_seg_km'));
    }

    public function test_show_calcula_a_leitura_dos_splits(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();
        $token = $this->bearerTokenFor($user);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")->assertOk();

        $resposta = $this->withHeader('Authorization', $token)
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        // Primeira metade a 408 s/km, segunda a 441: caiu de ritmo no fim.
        $this->assertSame('positive_split', $resposta->json('splits.veredito'));
        $this->assertSame(408, $resposta->json('splits.primeira_metade_seg_km'));
        $this->assertSame(441, $resposta->json('splits.segunda_metade_seg_km'));
        $this->assertSame(8.1, $resposta->json('splits.variacao_pct'));
        // FC média subiu de 141 para 149 bpm entre as metades.
        $this->assertSame(8, $resposta->json('splits.deriva_fc_bpm'));
        $this->assertCount(6, $resposta->json('splits.voltas'));
    }

    public function test_show_traz_o_comparativo_com_a_faixa_etaria(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertSame(38, $resposta->json('benchmarks.idade'));
        $this->assertEquals(91, $resposta->json('benchmarks.peso_kg'));
        // VO2max 49 do relógio cai entre o p50 (42,4) e o p75 (49,2) dos 30-39.
        $this->assertGreaterThan(70, $resposta->json('benchmarks.percentil.percentil'));
        $this->assertNotNull($resposta->json('benchmarks.age_grade.percentual'));
        $this->assertSame(5, $resposta->json('benchmarks.age_grade.distancia_referencia'));
        // FC máxima não foi medida: cai no Tanaka (208 - 0,7 x 38).
        $this->assertTrue($resposta->json('benchmarks.fc_maxima_estimada'));
        $this->assertSame(181, $resposta->json('benchmarks.fc_maxima'));
    }

    public function test_treino_leve_nao_e_comparado_como_se_fosse_prova(): void
    {
        $user = $this->usuarioCompleto();
        // FC média 145 sobre máxima estimada 181 = 80%: intensidade de treino.
        $sessao = $this->corrida($user);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertFalse($resposta->json('benchmarks.esforco_maximo'));
        $this->assertSame(0.801, $resposta->json('benchmarks.esforco_fracao_fcmax'));

        // O VDOT de um trote é baixo, mas não vira "percentil 5" na tela.
        $this->assertNotNull($resposta->json('benchmarks.vo2max_desempenho'));
        $this->assertNull($resposta->json('benchmarks.percentil_desempenho'));

        // O condicionamento continua sendo lido pelo VO2max do relógio.
        $this->assertGreaterThan(70, $resposta->json('benchmarks.percentil.percentil'));
    }

    public function test_esforco_de_prova_libera_o_comparativo_de_desempenho(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        // 165/181 = 91% da FC máxima: esforço de prova.
        $sessao->update(['fc_media' => 165, 'fc_maxima' => 178]);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertTrue($resposta->json('benchmarks.esforco_maximo'));
        $this->assertNotNull($resposta->json('benchmarks.percentil_desempenho.percentil'));
    }

    public function test_projecao_de_prova_prefere_a_previsao_do_garmin(): void
    {
        $user = $this->usuarioCompleto();
        SaudeMeta::where('user_id', $user->id)->update([
            'previsao_5k_seg' => 1553,
            'previsao_10k_seg' => 3387,
        ]);
        $sessao = $this->corrida($user);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertSame('garmin', $resposta->json('benchmarks.projecoes.fonte'));
        $this->assertSame(1553, $resposta->json('benchmarks.projecoes.tempos.5k'));
    }

    public function test_sem_previsao_do_garmin_treino_leve_nao_vira_projecao_de_prova(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        // Sem previsão do relógio e sem esforço de prova, não se projeta nada.
        $this->assertNull($resposta->json('benchmarks.projecoes'));
    }

    public function test_fc_maxima_medida_tem_prioridade_sobre_a_estimativa(): void
    {
        $user = $this->usuarioCompleto();
        SaudeMeta::where('user_id', $user->id)->update(['fc_maxima' => 190]);
        $sessao = $this->corrida($user);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson("/api/saude/cardio/{$sessao->id}")
            ->assertOk();

        $this->assertFalse($resposta->json('benchmarks.fc_maxima_estimada'));
        $this->assertSame(190, $resposta->json('benchmarks.fc_maxima'));
    }

    public function test_sessao_manual_nao_tem_detalhe_para_buscar(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => '2026-08-13',
            'modalidade' => 'corrida_rua',
            'duracao_min' => 30,
            'origem' => 'manual',
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Esta sessão foi lançada à mão: não há detalhe no Garmin para buscar.');
    }

    public function test_erro_do_garmin_vira_mensagem_em_portugues(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);

        Http::fake([self::BASE.'/atividade*' => Http::response(['erro' => 'tokens_invalidos'], 401)]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Os tokens do Garmin expiraram. Refaça o login: docker compose run --rm -it garmin python auth_cli.py');
    }

    public function test_nao_expoe_corrida_de_outro_usuario(): void
    {
        $dono = $this->usuarioCompleto();
        $sessao = $this->corrida($dono);
        $intruso = User::factory()->create(['email' => 'outro@teste.com']);

        $token = $this->bearerTokenFor($intruso);

        $this->withHeader('Authorization', $token)
            ->getJson("/api/saude/cardio/{$sessao->id}")->assertForbidden();
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/detalhe")->assertForbidden();
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")->assertForbidden();
    }

    public function test_analise_e_gravada_e_reaproveitada_sem_chamar_a_ia_de_novo(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();
        $this->fakeIa();
        $token = $this->bearerTokenFor($user);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertOk()
            ->assertJsonPath('dados.nota_geral', 7)
            ->assertJsonPath('modelo', 'claude-sonnet-5');

        $this->assertSame(1, $this->chamadasIa());

        // Segunda abertura: devolve o que está salvo, sem gastar token.
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertOk()
            ->assertJsonPath('dados.nota_geral', 7);

        $this->assertSame(1, $this->chamadasIa());
        $this->assertSame(1, SaudeCardioAnalise::count());

        // "Refazer análise" chama de novo e sobrescreve a mesma linha.
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/cardio/{$sessao->id}/analise", ['forcar' => true])
            ->assertOk();

        $this->assertSame(2, $this->chamadasIa());
        $this->assertSame(1, SaudeCardioAnalise::count());
    }

    public function test_o_veredito_de_pacing_vem_do_calculo_e_nao_da_ia(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();
        // A IA nem opina sobre o veredito: ele não está no schema da tool.
        $this->fakeIa();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertOk()
            ->assertJsonPath('dados.pacing.veredito', 'positive_split');
    }

    public function test_remonta_os_campos_planos_da_ia_na_estrutura_da_tela(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();
        $this->fakeIa();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertOk()
            ->assertJsonPath('dados.pacing.comentario', 'Saiu forte demais no km 2.')
            ->assertJsonPath('dados.esforco.deriva_cardiaca', 'FC subiu 8 bpm entre as metades.')
            ->assertJsonPath('dados.nutricao.pre_treino', 'Banana 40 min antes.')
            ->assertJsonPath('dados.nutricao.hidratacao', '500 ml ao longo da sessão.')
            ->assertJsonPath('dados.proximo_treino.tipo', 'regenerativo')
            ->assertJsonPath('dados.proximo_treino.quando', 'em 2 dias');
    }

    public function test_analise_sem_chave_de_ia_configurada_explica_o_que_falta(): void
    {
        config(['services.anthropic.key' => null]);

        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);
        $this->fakeGarmin();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A análise por IA não está configurada. Defina ANTHROPIC_API_KEY no .env do backend.');
    }

    public function test_garmin_fora_do_ar_nao_impede_a_analise(): void
    {
        $user = $this->usuarioCompleto();
        $sessao = $this->corrida($user);

        Http::fake([
            self::BASE.'/atividade*' => Http::response(['erro' => 'garmin_indisponivel'], 502),
            'api.anthropic.com/*' => Http::response($this->respostaIa()),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson("/api/saude/cardio/{$sessao->id}/analise")
            ->assertOk()
            ->assertJsonPath('dados.nota_geral', 7);

        // Sem splits, o veredito fica indefinido em vez de inventado.
        $this->assertSame('indefinido', SaudeCardioAnalise::sole()->dados['pacing']['veredito']);
    }

    // ============================================================
    // Fixtures
    // ============================================================

    /** Corrida real de 13/08: 5,5938 km em 2374,97 s, 6 voltas. */
    private function corrida(User $user): SaudeCardioSessao
    {
        return SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => '2026-08-13',
            'horario' => '19:07',
            'nome' => 'Osasco Corrida',
            'modalidade' => 'corrida_rua',
            'duracao_min' => 40,
            'distancia_km' => 5.59,
            'calorias' => 504,
            'fc_media' => 145,
            'fc_maxima' => 158,
            'origem' => 'garmin',
            'garmin_activity_id' => 23967967831,
        ]);
    }

    private function fakeGarmin(): void
    {
        Http::fake([self::BASE.'/atividade*' => Http::response($this->payloadGarmin())]);
    }

    /**
     * Resposta do sidecar para a corrida de 13/08, com os números reais do
     * relógio (inclusive as casas decimais que o import arredonda).
     *
     * @return array<string, mixed>
     */
    private function payloadGarmin(): array
    {
        return [
            'id' => 23967967831,
            'nome' => 'Osasco Corrida',
            'tipo' => 'running',
            'duracao_seg' => 2374.9729,
            'tempo_movimento_seg' => 2369.563,
            'distancia_m' => 5593.83,
            'fc_media' => 145,
            'fc_maxima' => 158,
            'fc_minima' => 96,
            'calorias' => 504,
            'cadencia_media' => 154.6,
            'passada_media_cm' => 92.1,
            'potencia_media' => 298,
            'passos' => 6072,
            'elevacao_ganho_m' => 45,
            'elevacao_perda_m' => 56,
            'training_effect_aerobico' => 3.4,
            'training_effect_anaerobico' => 0.2,
            'vo2max' => 49.0,
            'splits' => [
                $this->volta(1, 1000, 416.264, 127, 145, 155),
                $this->volta(2, 1000, 374.422, 149, 157, 161),
                $this->volta(3, 1000, 434.563, 148, 158, 133),
                $this->volta(4, 1000, 442.144, 149, 158, 148),
                $this->volta(5, 1000, 454.393, 148, 157, 144),
                $this->volta(6, 593.83, 253.187, 151, 154, 154),
            ],
            'zonas_fc' => [
                ['zona' => 1, 'segundos' => 29.997, 'fc_minima' => 99],
                ['zona' => 2, 'segundos' => 404.782, 'fc_minima' => 119],
                ['zona' => 3, 'segundos' => 1873.038, 'fc_minima' => 139],
                ['zona' => 4, 'segundos' => 22.082, 'fc_minima' => 158],
                ['zona' => 5, 'segundos' => 0.0, 'fc_minima' => 178],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function volta(
        int $numero,
        float $metros,
        float $segundos,
        int $fcMedia,
        int $fcMaxima,
        int $cadencia,
    ): array {
        return [
            'numero' => $numero,
            'distancia_m' => $metros,
            'duracao_seg' => $segundos,
            'fc_media' => $fcMedia,
            'fc_maxima' => $fcMaxima,
            'cadencia' => $cadencia,
            'potencia' => 300,
            'calorias' => 90,
            'elevacao_ganho_m' => 8,
            'elevacao_perda_m' => 9,
        ];
    }

    /** @param  array<string, mixed>  $override */
    private function fakeIa(array $override = []): void
    {
        Http::fake([
            self::BASE.'/atividade*' => Http::response($this->payloadGarmin()),
            'api.anthropic.com/*' => Http::response($this->respostaIa($override)),
        ]);
    }

    /** @param  array<string, mixed>  $override */
    private function respostaIa(array $override = []): array
    {
        return ['content' => [[
            'type' => 'tool_use',
            'name' => 'registrar_analise',
            // Schema plano: é assim que o modelo devolve de verdade. Com objetos
            // aninhados ele achata as chaves e o conteúdo se perde.
            'input' => array_merge([
                'nota_geral' => 7,
                'resumo' => 'Sessão sólida de base aeróbica.',
                'pacing_comentario' => 'Saiu forte demais no km 2.',
                'esforco_zona_predominante' => 'Z3, base aeróbica alta.',
                'esforco_deriva_cardiaca' => 'FC subiu 8 bpm entre as metades.',
                'esforco_comentario' => 'Custo aeróbico moderado.',
                'pontos_fortes' => ['Volume mantido', 'FC controlada'],
                'pontos_de_atencao' => ['Largada rápida demais'],
                'comparativo' => 'Acima da mediana dos 30-39.',
                'nutricao_pre_treino' => 'Banana 40 min antes.',
                'nutricao_durante' => 'Nada necessário nesta duração.',
                'nutricao_pos_treino' => '30 g de proteína em até 1 h.',
                'nutricao_hidratacao' => '500 ml ao longo da sessão.',
                'proximo_treino_tipo' => 'regenerativo',
                'proximo_treino_descricao' => '4 km em Z2.',
                'proximo_treino_quando' => 'em 2 dias',
                'meta_curto_prazo' => 'Fechar 5 km abaixo de 40 min.',
            ], $override),
        ]]];
    }

    private function chamadasIa(): int
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => str_contains($par[0]->url(), 'api.anthropic.com'))
            ->count();
    }

    /** Perfil completo o bastante para liberar comparativo e nutrição. */
    private function usuarioCompleto(): User
    {
        $user = User::factory()->create(['email' => 'garmin@teste.com']);
        config(['garmin.user_email' => 'garmin@teste.com']);

        SaudeMeta::create([
            'user_id' => $user->id,
            'sexo' => 'M',
            // Idade fixa em 38 independentemente de quando o teste roda. Tem de
            // ser no fuso do módulo: o app roda em UTC e, à noite no Brasil, as
            // duas datas divergem — a idade cairia para 37 e levaria junto a FC
            // máxima estimada por Tanaka.
            'data_nascimento' => now((string) config('saude.timezone'))->subYears(38)->toDateString(),
            'altura_cm' => 173,
            'nivel_atividade' => 'moderado',
            'peso_meta_kg' => 85,
            'vo2max' => 49.0,
            'fc_limiar' => 172,
        ]);

        SaudePeso::create([
            'user_id' => $user->id,
            'data' => '2026-08-12',
            'peso_kg' => 91.0,
        ]);

        return $user;
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }
}
