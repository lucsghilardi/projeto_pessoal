<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_access_newsletter_and_contacts_indexes(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/newsletter-subscribers')
            ->assertOk();

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/contact-messages')
            ->assertOk();

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/investor-leads')
            ->assertOk();
    }

    public function test_viewer_cannot_access_editor_routes(): void
    {
        $viewer = $this->makeUser('viewer');
        $record = Record::create([
            'name' => 'Record interno',
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/records')
            ->assertForbidden();

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson("/api/records/{$record->id}")
            ->assertForbidden();

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/admin/plans')
            ->assertForbidden();
    }

    public function test_editor_can_access_content_routes_but_not_settings_routes(): void
    {
        $editor = $this->makeUser('editor');

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->getJson('/api/records')
            ->assertOk();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->getJson('/api/admin/plans')
            ->assertOk();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->getJson('/api/users')
            ->assertForbidden();

        $this->withHeader('Authorization', $this->bearerTokenFor($editor))
            ->getJson('/api/admin/tracking-settings')
            ->assertForbidden();
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'password' => Hash::make('secret-123'),
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
