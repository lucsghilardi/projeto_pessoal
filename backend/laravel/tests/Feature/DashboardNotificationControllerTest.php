<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\ContactMessage;
use App\Models\InvestorLead;
use App\Models\NewsletterSubscriber;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_recent_dashboard_notifications_from_all_sources(): void
    {
        $admin = $this->makeUser('admin');
        $unit = $this->makeUnit();
        $campaign = Campaign::create([
            'title' => 'Campanha Verao',
            'slug' => 'campanha-verao',
            'conversion_type' => Campaign::CONVERSION_TYPE_FORM,
            'is_active' => true,
        ]);

        $newsletter = NewsletterSubscriber::create([
            'email' => 'newsletter@example.com',
        ]);
        $newsletter->forceFill(['created_at' => now()->subMinutes(40)])->save();

        $contact = ContactMessage::create([
            'unit_id' => $unit->id,
            'name' => 'Contato Nitro',
            'phone' => '11999999999',
            'email' => 'contato@example.com',
            'message' => 'Quero conhecer a unidade.',
            'consent_accepted_at' => now()->subMinutes(30),
        ]);
        $contact->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $investor = InvestorLead::create([
            'name' => 'Investidor Nitro',
            'phone' => '11988888888',
            'email' => 'investidor@example.com',
            'city' => 'Sao Paulo',
            'capital_range' => 'R$ 500k+',
            'investor_profile' => 'Empreendedor',
            'message' => null,
            'consent_accepted_at' => now()->subMinutes(20),
        ]);
        $investor->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $campaignLead = CampaignLead::create([
            'campaign_id' => $campaign->id,
            'name' => 'Lead Campanha',
            'phone' => '11977777777',
            'email' => 'lead@example.com',
            'birth_date' => '1990-01-01',
            'consent_accepted_at' => now()->subMinutes(10),
        ]);
        $campaignLead->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $response = $this->withHeader('Authorization', $this->bearerTokenFor($admin))
            ->getJson('/api/dashboard/notifications?limit=10');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'items' => [
                    [
                        'id',
                        'source',
                        'label',
                        'title',
                        'subtitle',
                        'description',
                        'href',
                        'occurred_at',
                    ],
                ],
                'generated_at',
                'poll_interval_seconds',
            ]);

        $items = collect($response->json('items'));

        $this->assertSame(
            ['campaign_lead', 'investor', 'contact', 'newsletter'],
            $items->pluck('source')->all()
        );
        $this->assertSame('campaign_lead:' . $campaignLead->id, $items->first()['id']);
        $this->assertSame('/dashboard/records', $items->first()['href']);
    }

    public function test_viewer_receives_only_sources_allowed_for_their_role(): void
    {
        $viewer = $this->makeUser('viewer');
        $campaign = Campaign::create([
            'title' => 'Campanha Interna',
            'slug' => 'campanha-interna',
            'conversion_type' => Campaign::CONVERSION_TYPE_FORM,
            'is_active' => true,
        ]);

        NewsletterSubscriber::create([
            'email' => 'viewer-newsletter@example.com',
        ]);

        CampaignLead::create([
            'campaign_id' => $campaign->id,
            'name' => 'Lead Oculto',
            'phone' => '11966666666',
            'email' => 'oculto@example.com',
            'birth_date' => '1990-01-01',
            'consent_accepted_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/dashboard/notifications?limit=10')
            ->assertOk();

        $sources = collect($response->json('items'))->pluck('source');

        $this->assertTrue($sources->contains('newsletter'));
        $this->assertFalse($sources->contains('campaign_lead'));
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'password' => Hash::make('secret-123'),
        ]);
    }

    private function makeUnit(): Unit
    {
        return Unit::create([
            'name' => 'Nitrogym Teste',
            'slug' => 'nitrogym-teste',
            'address_line' => 'Rua Teste, 123',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'latitude' => -23.55052,
            'longitude' => -46.633308,
            'is_active' => true,
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
