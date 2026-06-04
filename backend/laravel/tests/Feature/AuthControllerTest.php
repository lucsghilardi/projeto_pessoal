<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-123',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);
    }

    public function test_login_is_rate_limited_after_repeated_invalid_attempts(): void
    {
        $user = User::factory()->create([
            'email' => 'rate-limit@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'admin',
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
                ->assertUnauthorized()
                ->assertJson([
                    'message' => 'Email ou senha invalidos.',
                ]);
        }

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(429)
            ->assertJsonStructure([
                'message',
                'retry_after',
            ]);
    }

    public function test_logout_invalidates_the_current_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'admin',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-123',
        ]);

        $token = $loginResponse->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson([
                'message' => 'Sessao encerrada com sucesso.',
            ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'viewer',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'secret-123',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Email ou senha invalidos.',
            ]);
    }

    public function test_inactive_user_token_is_rejected_by_protected_routes(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled-session@example.com',
            'password' => Hash::make('secret-123'),
            'role' => 'editor',
            'is_active' => true,
        ]);

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-123',
        ])->json('access_token');

        $user->forceFill([
            'is_active' => false,
        ])->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Sessao invalida para este usuario.',
            ]);
    }
}
