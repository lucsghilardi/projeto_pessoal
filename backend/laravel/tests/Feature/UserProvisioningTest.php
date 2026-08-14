<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_starts_with_the_default_data(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('secret-123'),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->postJson('/api/users', [
                'name' => 'Familiar',
                'email' => 'familiar@example.com',
                'role' => 'editor',
                'password' => 'secure123',
                'password_confirmation' => 'secure123',
            ])
            ->assertCreated();

        $novo = User::where('email', 'familiar@example.com')->firstOrFail();

        $this->assertSame(22, $this->contar('finance_categories', $novo)); // 17 despesa + 5 receita
        $this->assertSame(1, $this->contar('bank_accounts', $novo));
        $this->assertSame(4, $this->contar('investment_institutions', $novo));
        $this->assertSame(2, $this->contar('saude_treinos', $novo));

        // Dados pessoais do dono não são copiados para ninguém. O banco de teste
        // tem o admin de bootstrap (seed_default_admin_user) com as fichas
        // preenchidas pelas migrations antigas, então a contagem precisa ser
        // escopada — as fichas do usuário novo nascem vazias.
        $this->assertSame(0, $this->contar('saude_suplementos', $novo));
        $this->assertSame(0, DB::table('saude_exercicios')
            ->whereIn('treino_id', DB::table('saude_treinos')->where('user_id', $novo->id)->select('id'))
            ->count());

        // Sem conta bancária o "Pagar" responde 422; é o que destrava o financeiro.
        $this->assertDatabaseHas('bank_accounts', [
            'user_id' => $novo->id,
            'name' => 'Conta principal',
        ]);

        // O admin que criou a conta não é tocado.
        $this->assertSame(0, $this->contar('finance_categories', $admin));
    }

    public function test_provisioning_is_idempotent(): void
    {
        $user = User::factory()->create();
        $service = app(UserProvisioningService::class);

        $service->provisionar($user);
        $service->provisionar($user);

        $this->assertSame(22, $this->contar('finance_categories', $user));
        $this->assertSame(1, $this->contar('bank_accounts', $user));
        $this->assertSame(4, $this->contar('investment_institutions', $user));
        $this->assertSame(2, $this->contar('saude_treinos', $user));
    }

    public function test_artisan_command_backfills_an_existing_user(): void
    {
        $antigo = User::factory()->create(['email' => 'antigo@example.com']);

        $this->assertSame(0, $this->contar('finance_categories', $antigo));

        $this->artisan('usuarios:provisionar', ['--user' => 'antigo@example.com'])
            ->assertSuccessful();

        $this->assertSame(22, $this->contar('finance_categories', $antigo));
    }

    public function test_artisan_command_fails_for_unknown_user(): void
    {
        $this->artisan('usuarios:provisionar', ['--user' => 'ninguem@example.com'])
            ->assertFailed();
    }

    private function contar(string $tabela, User $user): int
    {
        return DB::table($tabela)->where('user_id', $user->id)->count();
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
