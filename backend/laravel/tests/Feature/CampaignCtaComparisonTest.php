<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignCtaComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_create_cta_campaign_with_comparison_values(): void
    {
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/campaigns', $this->campaignPayload([
                'cta_comparison_enabled' => true,
                'cta_comparison_from_value' => 'R$ 199,90',
                'cta_comparison_to_value' => 'R$ 99,90',
            ]))
            ->assertCreated()
            ->assertJsonPath('cta_comparison_enabled', true)
            ->assertJsonPath('cta_comparison_from_value', 'R$ 199,90')
            ->assertJsonPath('cta_comparison_to_value', 'R$ 99,90');
    }

    public function test_cta_comparison_requires_from_and_to_values_when_enabled(): void
    {
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/campaigns', $this->campaignPayload([
                'cta_comparison_enabled' => true,
                'cta_comparison_from_value' => '',
                'cta_comparison_to_value' => '',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cta_comparison_from_value',
                'cta_comparison_to_value',
            ]);
    }

    public function test_cta_campaign_can_keep_comparison_disabled(): void
    {
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/campaigns', $this->campaignPayload([
                'cta_comparison_enabled' => false,
                'cta_comparison_from_value' => 'R$ 199,90',
                'cta_comparison_to_value' => 'R$ 99,90',
            ]))
            ->assertCreated()
            ->assertJsonPath('cta_comparison_enabled', false)
            ->assertJsonPath('cta_comparison_from_value', 'R$ 199,90')
            ->assertJsonPath('cta_comparison_to_value', 'R$ 99,90');
    }

    public function test_form_campaign_ignores_cta_comparison_fields(): void
    {
        $editor = $this->makeEditor();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->postJson('/api/admin/campaigns', $this->campaignPayload([
                'conversion_type' => Campaign::CONVERSION_TYPE_FORM,
                'cta_url' => null,
                'cta_comparison_enabled' => true,
                'cta_comparison_from_value' => 'R$ 199,90',
                'cta_comparison_to_value' => 'R$ 99,90',
            ]))
            ->assertCreated()
            ->assertJsonPath('conversion_type', Campaign::CONVERSION_TYPE_FORM)
            ->assertJsonPath('cta_comparison_enabled', false)
            ->assertJsonPath('cta_comparison_from_value', null)
            ->assertJsonPath('cta_comparison_to_value', null);
    }

    public function test_public_endpoint_returns_comparison_fields_for_cta_campaign(): void
    {
        Campaign::create([
            'title' => 'Campanha CTA',
            'slug' => 'campanha-cta',
            'conversion_type' => Campaign::CONVERSION_TYPE_CTA,
            'form_title' => 'Oferta especial',
            'cta_label' => 'Ver oferta',
            'cta_url' => '/planos',
            'cta_comparison_enabled' => true,
            'cta_comparison_from_value' => 'R$ 199,90',
            'cta_comparison_to_value' => 'R$ 99,90',
            'gallery_enabled' => false,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->getJson('/api/campaigns/campanha-cta')
            ->assertOk()
            ->assertJsonPath('cta_comparison_enabled', true)
            ->assertJsonPath('cta_comparison_from_value', 'R$ 199,90')
            ->assertJsonPath('cta_comparison_to_value', 'R$ 99,90');
    }

    private function campaignPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Campanha CTA',
            'slug' => 'campanha-cta',
            'meta_title' => null,
            'eyebrow' => 'Oferta',
            'subtitle' => 'Subtitulo da campanha',
            'description' => 'Conteudo da campanha',
            'conversion_type' => Campaign::CONVERSION_TYPE_CTA,
            'form_title' => 'Oferta especial',
            'form_description' => 'Clique para ver os detalhes.',
            'success_message' => null,
            'cta_label' => 'Ver oferta',
            'cta_url' => '/planos',
            'whatsapp_number' => null,
            'cta_comparison_enabled' => false,
            'cta_comparison_from_value' => null,
            'cta_comparison_to_value' => null,
            'gallery_enabled' => false,
            'gallery_title' => null,
            'sort_order' => 0,
            'is_active' => true,
        ], $overrides);
    }

    private function makeEditor(): User
    {
        return User::factory()->create([
            'role' => 'editor',
            'password' => Hash::make('secret-123'),
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
