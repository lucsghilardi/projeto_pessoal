<?php

namespace Tests\Feature;

use App\Models\SaudeSono;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SaudeSonoTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_de_sono_com_ownership(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $id = $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sono', [
                'data' => '2026-08-08',
                'duracao_min' => 450,
                'inicio' => '23:30',
                'fim' => '07:00',
                'observacao' => 'dormi sem o relógio',
            ])
            ->assertCreated()
            ->assertJsonPath('duracao_min', 450)
            ->assertJsonPath('origem', 'manual')
            ->json('id');

        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/sono/{$id}", ['duracao_min' => 420])
            ->assertOk()
            ->assertJsonPath('duracao_min', 420);

        // Outro usuário não altera nem remove.
        $intruso = $this->bearerTokenFor(User::factory()->create());
        $this->withHeader('Authorization', $intruso)
            ->deleteJson("/api/saude/sono/{$id}")
            ->assertForbidden();

        // Re-login: o guard memoiza o último usuário logado no mesmo teste.
        $token = $this->bearerTokenFor($user);
        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/saude/sono/{$id}")
            ->assertOk();

        $this->assertSame(0, SaudeSono::count());
    }

    public function test_registrar_duas_vezes_no_mesmo_dia_substitui(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sono', ['data' => '2026-08-08', 'duracao_min' => 400])
            ->assertCreated();

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sono', ['data' => '2026-08-08', 'duracao_min' => 450])
            ->assertCreated()
            ->assertJsonPath('duracao_min', 450);

        $this->assertSame(1, SaudeSono::count());
    }

    public function test_editar_noite_do_garmin_preserva_as_fases_e_a_marca_como_manual(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $noite = SaudeSono::create([
            'user_id' => $user->id,
            'data' => '2026-08-08',
            'duracao_min' => 312,
            'profundo_min' => 26,
            'leve_min' => 286,
            'score' => 52,
            'score_qualificador' => 'POOR',
            'origem' => 'garmin',
        ]);

        // O formulário só edita a duração — o que ele não manda tem que ficar.
        $this->withHeader('Authorization', $token)
            ->putJson("/api/saude/sono/{$noite->id}", ['duracao_min' => 330])
            ->assertOk()
            ->assertJsonPath('duracao_min', 330)
            ->assertJsonPath('profundo_min', 26)
            ->assertJsonPath('score', 52)
            ->assertJsonPath('origem', 'manual');
    }

    public function test_index_filtra_por_periodo_e_isola_por_usuario(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $this->noite($user, '2026-08-06', 400);
        $this->noite($user, '2026-08-08', 450);
        $this->noite($user, '2026-07-01', 300); // fora da janela
        $this->noite(User::factory()->create(), '2026-08-07', 500); // de outro usuário

        $this->withHeader('Authorization', $token)
            ->getJson('/api/saude/sono?de=2026-08-01&ate=2026-08-08')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.duracao_min', 400)
            ->assertJsonPath('1.duracao_min', 450);
    }

    public function test_overview_traz_a_noite_do_dia_e_a_meta(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $hoje = now((string) config('saude.timezone'))->toDateString();

        $this->noite($user, $hoje, 420);

        $this->withHeader('Authorization', $token)
            ->putJson('/api/saude/meta', ['sono_meta_min' => 450])
            ->assertOk();

        $this->withHeader('Authorization', $token)
            ->getJson("/api/saude/overview?data={$hoje}")
            ->assertOk()
            ->assertJsonPath('sono.noite.duracao_min', 420)
            ->assertJsonPath('sono.meta_min', 450)
            ->assertJsonCount(1, 'sono.recentes');
    }

    public function test_historico_de_calorias_traz_o_sono_da_noite(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $hoje = now((string) config('saude.timezone'))->toDateString();

        $this->noite($user, $hoje, 420);

        $historico = $this->withHeader('Authorization', $token)
            ->getJson("/api/saude/nutricao?data={$hoje}")
            ->assertOk()
            ->json('historico');

        $ultimo = end($historico);
        $this->assertSame($hoje, $ultimo['data']);
        $this->assertSame(420, $ultimo['sono_min']);
        // Dia sem medição fica null, não zero.
        $this->assertNull($historico[0]['sono_min']);
    }

    public function test_duracao_fora_do_intervalo_e_rejeitada(): void
    {
        $token = $this->bearerTokenFor(User::factory()->create());

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sono', ['data' => '2026-08-08', 'duracao_min' => 1500])
            ->assertStatus(422);
    }

    private function noite(User $user, string $data, int $minutos): SaudeSono
    {
        return SaudeSono::create([
            'user_id' => $user->id,
            'data' => $data,
            'duracao_min' => $minutos,
            'origem' => 'garmin',
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }
}
