<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlanDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_endpoint_serializes_discount_state_and_current_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 12:00:00'));

        $unit = $this->makeUnit();

        Plan::create($this->planData($unit, [
            'name' => 'Desconto ativo',
            'price' => 199.90,
            'discount_enabled' => true,
            'discount_price' => 149.90,
            'discount_starts_at' => Carbon::now()->subDay(),
            'discount_ends_at' => Carbon::now()->addDay(),
            'sort_order' => 0,
        ]));

        Plan::create($this->planData($unit, [
            'name' => 'Desconto futuro',
            'price' => 209.90,
            'discount_enabled' => true,
            'discount_price' => 159.90,
            'discount_starts_at' => Carbon::now()->addDay(),
            'discount_ends_at' => Carbon::now()->addDays(2),
            'sort_order' => 1,
        ]));

        Plan::create($this->planData($unit, [
            'name' => 'Desconto expirado',
            'price' => 219.90,
            'discount_enabled' => true,
            'discount_price' => 169.90,
            'discount_starts_at' => Carbon::now()->subDays(3),
            'discount_ends_at' => Carbon::now()->subDay(),
            'sort_order' => 2,
        ]));

        Plan::create($this->planData($unit, [
            'name' => 'Desconto sem prazo',
            'price' => 229.90,
            'discount_enabled' => true,
            'discount_price' => 179.90,
            'discount_starts_at' => null,
            'discount_ends_at' => null,
            'sort_order' => 3,
        ]));

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonPath('0.name', 'Desconto ativo')
            ->assertJsonPath('0.has_active_discount', true)
            ->assertJsonPath('0.current_price', 149.9)
            ->assertJsonPath('1.name', 'Desconto futuro')
            ->assertJsonPath('1.has_active_discount', false)
            ->assertJsonPath('1.current_price', 209.9)
            ->assertJsonPath('2.name', 'Desconto expirado')
            ->assertJsonPath('2.has_active_discount', false)
            ->assertJsonPath('2.current_price', 219.9)
            ->assertJsonPath('3.name', 'Desconto sem prazo')
            ->assertJsonPath('3.has_active_discount', true)
            ->assertJsonPath('3.current_price', 179.9);
    }

    public function test_editor_can_create_plan_with_programmed_discount(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 12:00:00'));

        $unit = $this->makeUnit();
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/plans', $this->requestPayload($unit, [
                'discount_enabled' => true,
                'discount_price' => 149.90,
                'discount_starts_at' => Carbon::now()->subHour()->toDateTimeString(),
                'discount_ends_at' => Carbon::now()->addHour()->toDateTimeString(),
            ]))
            ->assertCreated()
            ->assertJsonPath('discount_enabled', true)
            ->assertJsonPath('discount_price', 149.9)
            ->assertJsonPath('has_active_discount', true)
            ->assertJsonPath('current_price', 149.9);
    }

    public function test_active_discount_requires_promotional_price(): void
    {
        $unit = $this->makeUnit();
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/plans', $this->requestPayload($unit, [
                'discount_enabled' => true,
                'discount_price' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_price']);
    }

    public function test_promotional_price_must_be_lower_than_original_price(): void
    {
        $unit = $this->makeUnit();
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/plans', $this->requestPayload($unit, [
                'discount_enabled' => true,
                'discount_price' => 199.90,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_price']);
    }

    private function makeUnit(): Unit
    {
        return Unit::create([
            'name' => 'Nitrogym Teste',
            'slug' => 'nitrogym-teste',
            'address_line' => 'Rua Teste, 100',
            'neighborhood' => 'Centro',
            'city' => 'Cotia',
            'state' => 'SP',
            'postal_code' => '06700-000',
            'latitude' => -23.603333,
            'longitude' => -46.919167,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function makeEditor(): User
    {
        return User::factory()->create([
            'role' => 'editor',
            'password' => Hash::make('secret-123'),
        ]);
    }

    private function planData(Unit $unit, array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $unit->id,
            'name' => 'Plano Nitro',
            'subtitle' => 'Mensal',
            'description' => 'Plano de teste.',
            'price' => 199.90,
            'price_suffix' => '/Mês',
            'primary_features' => ['Musculacao'],
            'secondary_features' => [],
            'sort_order' => 0,
            'is_featured' => false,
            'is_active' => true,
        ], $overrides);
    }

    private function requestPayload(Unit $unit, array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $unit->id,
            'name' => 'Plano Promocional',
            'subtitle' => 'Mensal',
            'description' => 'Plano com desconto.',
            'price' => 199.90,
            'price_suffix' => '/Mês',
            'badge_text' => null,
            'cta_label' => 'Escolher',
            'cta_url' => null,
            'primary_features' => ['Musculacao'],
            'secondary_features' => [],
            'highlight_text' => null,
            'sort_order' => 0,
            'is_featured' => false,
            'is_active' => true,
        ], $overrides);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
