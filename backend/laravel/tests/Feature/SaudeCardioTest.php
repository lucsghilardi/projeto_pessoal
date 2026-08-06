<?php

namespace Tests\Feature;

use App\Models\SaudeCardioSessao;
use App\Models\SaudeTreino;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SaudeCardioTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_de_cardio_com_ownership(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $id = $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/cardio', [
                'data' => '2026-08-05',
                'horario' => '19:19',
                'modalidade' => 'corrida_rua',
                'duracao_min' => 22,
                'distancia_km' => 3.15,
                'calorias' => 288,
                'fc_media' => 143,
            ])
            ->assertCreated()
            ->assertJsonPath('modalidade', 'corrida_rua')
            ->assertJsonPath('origem', 'manual')
            ->json('id');

        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/cardio/{$id}", [
                'data' => '2026-08-05',
                'modalidade' => 'esteira',
                'duracao_min' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('modalidade', 'esteira')
            ->assertJsonPath('duracao_min', 25);

        // Outro usuário não altera nem remove.
        $intruso = $this->bearerTokenFor(User::factory()->create());
        $this->withHeader('Authorization', $intruso)
            ->deleteJson("/api/saude/cardio/{$id}")
            ->assertForbidden();

        // Re-login: o guard memoiza o último usuário logado no mesmo teste.
        $token = $this->bearerTokenFor($user);
        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/saude/cardio/{$id}")
            ->assertOk();

        $this->assertSame(0, SaudeCardioSessao::count());
    }

    public function test_varias_sessoes_no_mesmo_dia(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $treino = SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino A', 'posicao' => 1]);

        // Cardio ao fim do treino A, de manhã...
        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/cardio', [
                'data' => '2026-08-05',
                'horario' => '07:30',
                'modalidade' => 'esteira',
                'duracao_min' => 12,
                'treino_id' => $treino->id,
            ])
            ->assertCreated()
            ->assertJsonPath('treino.nome', 'Treino A');

        // ...e uma corrida avulsa à noite. A tabela antiga não permitia isso.
        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/cardio', [
                'data' => '2026-08-05',
                'horario' => '19:19',
                'modalidade' => 'corrida_rua',
                'duracao_min' => 22,
            ])
            ->assertCreated();

        $this->assertSame(2, SaudeCardioSessao::where('data', '2026-08-05')->count());
    }

    public function test_resumo_soma_minutos_distancia_e_sessoes(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $this->cardio($user, ['data' => '2026-08-04', 'modalidade' => 'corrida_rua', 'duracao_min' => 20, 'distancia_km' => 2.26, 'calorias' => 193]);
        $this->cardio($user, ['data' => '2026-08-05', 'modalidade' => 'corrida_rua', 'duracao_min' => 22, 'distancia_km' => 3.15, 'calorias' => 288]);
        $this->cardio($user, ['data' => '2026-08-05', 'modalidade' => 'esteira', 'duracao_min' => 12, 'distancia_km' => 1.5]);
        // Fora da janela consultada.
        $this->cardio($user, ['data' => '2026-07-01', 'modalidade' => 'bike', 'duracao_min' => 60]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/saude/cardio/resumo?de=2026-08-01&ate=2026-08-05')
            ->assertOk()
            ->assertJsonPath('sessoes', 3)
            ->assertJsonPath('minutos', 54)
            ->assertJsonPath('distancia_km', 6.91)
            ->assertJsonPath('calorias', 481)
            ->assertJsonPath('por_modalidade.corrida_rua', 42)
            ->assertJsonPath('por_modalidade.esteira', 12);
    }

    public function test_overview_traz_cardio_de_hoje_e_da_semana(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $this->cardio($user, ['data' => '2026-08-05', 'modalidade' => 'corrida_rua', 'duracao_min' => 22]);
        $this->cardio($user, ['data' => '2026-08-02', 'modalidade' => 'bike', 'duracao_min' => 30]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/saude/overview?data=2026-08-05')
            ->assertOk()
            ->assertJsonCount(1, 'cardio_hoje')
            ->assertJsonPath('cardio_hoje.0.modalidade', 'corrida_rua')
            ->assertJsonCount(2, 'cardio_recentes')
            ->assertJsonPath('cardio_semana.sessoes', 2)
            ->assertJsonPath('cardio_semana.minutos', 52);
    }

    public function test_overview_expoe_o_tipo_da_ficha(): void
    {
        // O check-in usa `tipo` para não oferecer ficha de cardio como treino
        // do dia — sem ele, marcar cardio sobrescreveria o Treino A.
        $user = User::factory()->create();
        SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino A', 'tipo' => 'musculacao', 'posicao' => 1]);
        SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Cardio Longo Z2', 'tipo' => 'cardio', 'posicao' => 2]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson('/api/saude/overview')
            ->assertOk()
            ->assertJsonPath('treinos.0.tipo', 'musculacao')
            ->assertJsonPath('treinos.1.tipo', 'cardio');
    }

    public function test_modalidade_invalida_e_rejeitada(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/saude/cardio', [
                'data' => '2026-08-05',
                'modalidade' => 'natacao_sincronizada',
                'duracao_min' => 30,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('modalidade');
    }

    /** @param  array<string, mixed>  $attributes */
    private function cardio(User $user, array $attributes): SaudeCardioSessao
    {
        return SaudeCardioSessao::create($attributes + [
            'user_id' => $user->id,
            'modalidade' => 'outro',
            'duracao_min' => 20,
            'origem' => 'manual',
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }
}
