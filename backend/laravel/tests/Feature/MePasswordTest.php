<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_troca_a_propria_senha(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-atual-1')]);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->putJson('/api/me/password', [
                'senha_atual' => 'senha-atual-1',
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'access_token', 'expires_in']);

        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));

        // O token novo tem que funcionar de imediato: a rota do Next reescreve
        // o cookie com ele, senão a pessoa é deslogada ao trocar a senha.
        $this->withHeader('Authorization', 'Bearer '.$resposta->json('access_token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_senha_atual_errada_nao_troca_nada(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-atual-1')]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->putJson('/api/me/password', [
                'senha_atual' => 'chute-errado',
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'A senha atual não confere.']);

        $this->assertTrue(Hash::check('senha-atual-1', $user->fresh()->password));
    }

    public function test_confirmacao_diferente_e_recusada(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-atual-1')]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->putJson('/api/me/password', [
                'senha_atual' => 'senha-atual-1',
                'password' => 'novaSenha123',
                'password_confirmation' => 'outraCoisa123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /** O campo "senha atual" seria um oráculo de senha sem teto de tentativas. */
    public function test_tentativas_repetidas_sao_barradas(): void
    {
        $user = User::factory()->create(['password' => Hash::make('senha-atual-1')]);
        $token = $this->bearerTokenFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Authorization', $token)
                ->putJson('/api/me/password', [
                    'senha_atual' => "chute-{$i}",
                    'password' => 'novaSenha123',
                    'password_confirmation' => 'novaSenha123',
                ])
                ->assertStatus(422);
        }

        $this->withHeader('Authorization', $token)
            ->putJson('/api/me/password', [
                'senha_atual' => 'senha-atual-1',
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ])
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after']);

        $this->assertTrue(Hash::check('senha-atual-1', $user->fresh()->password));
    }

    public function test_sem_token_nao_passa(): void
    {
        $this->putJson('/api/me/password', [
            'senha_atual' => 'seja-la-o-que-for',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertUnauthorized();
    }

    public function test_usuario_desativado_nao_troca_senha(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('senha-atual-1'),
            'is_active' => true,
        ]);

        $token = $this->bearerTokenFor($user);
        $user->update(['is_active' => false]);

        $this->withHeader('Authorization', $token)
            ->putJson('/api/me/password', [
                'senha_atual' => 'senha-atual-1',
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ])
            ->assertUnauthorized();

        $this->assertTrue(Hash::check('senha-atual-1', $user->fresh()->password));
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }
}
