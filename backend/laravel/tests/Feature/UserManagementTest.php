<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_panel_user(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
        ]);

        $response = $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->postJson('/api/users', [
                'name' => 'Equipe Conteudo',
                'email' => 'editor@example.com',
                'role' => 'editor',
                'password' => 'secure123',
                'password_confirmation' => 'secure123',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('name', 'Equipe Conteudo')
            ->assertJsonPath('email', 'editor@example.com')
            ->assertJsonPath('role', 'editor')
            ->assertJsonPath('is_active', true);

        $this->assertDatabaseHas('users', [
            'email' => 'editor@example.com',
            'role' => 'editor',
            'is_active' => true,
        ]);

        $createdUser = User::where('email', 'editor@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('secure123', $createdUser->password));
    }

    public function test_non_admin_cannot_create_users(): void
    {
        $viewer = User::factory()->create([
            'role' => 'viewer',
            'password' => Hash::make('secret-123'),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->postJson('/api/users', [
                'name' => 'Sem Permissao',
                'email' => 'denied@example.com',
                'role' => 'viewer',
                'password' => 'secure123',
                'password_confirmation' => 'secure123',
            ])
            ->assertForbidden();
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->putJson("/api/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'editor',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Seu proprio nivel de acesso nao pode ser alterado por esta tela.',
            ]);
    }

    public function test_admin_can_demote_another_admin_when_more_than_one_exists(): void
    {
        $primaryAdmin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
        ]);

        $secondaryAdmin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($primaryAdmin))
            ->putJson("/api/users/{$secondaryAdmin->id}", [
                'name' => $secondaryAdmin->name,
                'email' => $secondaryAdmin->email,
                'role' => 'editor',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('role', 'editor');

        $this->assertDatabaseHas('users', [
            'id' => $secondaryAdmin->id,
            'role' => 'editor',
        ]);
    }

    public function test_admin_can_update_user_status_and_reset_password(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'name' => 'Equipe Conteudo',
            'email' => 'editor@example.com',
            'role' => 'editor',
            'password' => Hash::make('secret-123'),
            'is_active' => true,
        ]);

        $response = $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->putJson("/api/users/{$user->id}", [
                'name' => 'Equipe Conteudo 2',
                'email' => 'editor2@example.com',
                'role' => 'viewer',
                'is_active' => false,
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', 'Equipe Conteudo 2')
            ->assertJsonPath('email', 'editor2@example.com')
            ->assertJsonPath('role', 'viewer')
            ->assertJsonPath('is_active', false);

        $user->refresh();

        $this->assertSame('Equipe Conteudo 2', $user->name);
        $this->assertSame('editor2@example.com', $user->email);
        $this->assertSame('viewer', $user->role);
        $this->assertFalse($user->is_active);
        $this->assertTrue(Hash::check('novaSenha123', $user->password));
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->putJson("/api/users/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => false,
            ])
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Seu proprio acesso nao pode ser desativado por esta tela.',
            ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
