<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TestimonialManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_returns_only_active_testimonials_in_order(): void
    {
        Testimonial::create([
            'author_name' => 'Segundo aluno',
            'quote' => 'Depoimento ativo secundario.',
            'rating' => 4,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Testimonial::create([
            'author_name' => 'Aluno oculto',
            'quote' => 'Este depoimento nao deve aparecer.',
            'rating' => 5,
            'sort_order' => 1,
            'is_active' => false,
        ]);

        Testimonial::create([
            'author_name' => 'Primeiro aluno',
            'quote' => 'Depoimento ativo principal.',
            'rating' => 5,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->getJson('/api/testimonials')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.author_name', 'Primeiro aluno')
            ->assertJsonPath('1.author_name', 'Segundo aluno')
            ->assertJsonMissing(['author_name' => 'Aluno oculto']);
    }

    public function test_editor_can_manage_testimonials(): void
    {
        $editor = User::factory()->create([
            'role' => 'editor',
            'password' => Hash::make('secret-123'),
        ]);

        $token = $this->bearerTokenFor($editor);

        $created = $this->withHeader('Authorization', $token)
            ->postJson('/api/admin/testimonials', [
                'author_name' => 'Aluno Nitro',
                'author_label' => 'Unidade Raposo',
                'quote' => 'A estrutura mudou minha rotina de treino.',
                'rating' => 5,
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('author_name', 'Aluno Nitro')
            ->json();

        $testimonialId = $created['id'];

        $secondTestimonial = Testimonial::create([
            'author_name' => 'Outra aluna',
            'quote' => 'Gosto da energia da academia.',
            'rating' => 5,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', $token)
            ->putJson("/api/admin/testimonials/{$testimonialId}", [
                'author_name' => 'Aluno Nitro Atualizado',
                'author_label' => null,
                'quote' => 'A estrutura e o atendimento mudaram minha rotina.',
                'rating' => 4,
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('author_name', 'Aluno Nitro Atualizado')
            ->assertJsonPath('rating', 4);

        $this->withHeader('Authorization', $token)
            ->putJson('/api/admin/testimonials/reorder', [
                'testimonial_ids' => [$secondTestimonial->id, $testimonialId],
            ])
            ->assertOk()
            ->assertJsonPath('0.id', $secondTestimonial->id)
            ->assertJsonPath('1.id', $testimonialId);

        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/admin/testimonials/{$testimonialId}")
            ->assertOk()
            ->assertJsonPath('message', 'Depoimento excluido com sucesso.');

        $this->assertDatabaseMissing('testimonials', [
            'id' => $testimonialId,
        ]);
    }

    public function test_viewer_cannot_access_testimonial_admin_routes(): void
    {
        $viewer = User::factory()->create([
            'role' => 'viewer',
            'password' => Hash::make('secret-123'),
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($viewer))
            ->getJson('/api/admin/testimonials')
            ->assertForbidden();
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer ' . Auth::guard('api')->login($user);
    }
}
