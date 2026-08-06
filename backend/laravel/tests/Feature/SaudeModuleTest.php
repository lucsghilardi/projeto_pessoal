<?php

namespace Tests\Feature;

use App\Jobs\EnviarLembretesSuplementos;
use App\Models\SaudeLembrete;
use App\Models\SaudePeso;
use App\Models\SaudeSuplemento;
use App\Models\SaudeSuplementoCheckin;
use App\Models\SaudeTreino;
use App\Models\SaudeTreinoSessao;
use App\Models\User;
use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class SaudeModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkin_marca_e_desfaz_com_progresso(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $suplemento = $this->suplemento($user, ['horario' => '07:00']);
        $this->suplemento($user, ['nome' => 'Outro', 'horario' => '12:00']);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/suplementos/{$suplemento->id}/checkin", ['data' => '2026-08-05'])
            ->assertCreated()
            ->assertJsonPath('progresso.tomados', 1)
            ->assertJsonPath('progresso.total', 2);

        // Idempotente: repetir o check-in não duplica a linha.
        $this->withHeader('Authorization', $token)
            ->postJson("/api/saude/suplementos/{$suplemento->id}/checkin", ['data' => '2026-08-05'])
            ->assertCreated();

        $this->assertSame(1, SaudeSuplementoCheckin::where('suplemento_id', $suplemento->id)->count());

        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/saude/suplementos/{$suplemento->id}/checkin?data=2026-08-05")
            ->assertOk()
            ->assertJsonPath('progresso.tomados', 0);

        $this->assertSame(0, SaudeSuplementoCheckin::where('suplemento_id', $suplemento->id)->count());
    }

    public function test_sessao_e_unica_por_dia_e_troca_de_treino(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $treinoA = SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino A', 'posicao' => 1]);
        $treinoB = SaudeTreino::create(['user_id' => $user->id, 'nome' => 'Treino B', 'posicao' => 2]);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sessoes', ['treino_id' => $treinoA->id, 'data' => '2026-08-05'])
            ->assertCreated();

        // Marcar outro treino no mesmo dia troca, não duplica.
        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/sessoes', ['treino_id' => $treinoB->id, 'data' => '2026-08-05'])
            ->assertCreated()
            ->assertJsonPath('treino_id', $treinoB->id);

        $this->assertSame(1, SaudeTreinoSessao::where('user_id', $user->id)->count());
    }

    public function test_peso_faz_upsert_por_data(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/pesos', ['data' => '2026-08-05', 'peso_kg' => 92.5])
            ->assertCreated();

        $this->withHeader('Authorization', $token)
            ->postJson('/api/saude/pesos', ['data' => '2026-08-05', 'peso_kg' => 92.1])
            ->assertCreated();

        $pesos = SaudePeso::where('user_id', $user->id)->get();
        $this->assertCount(1, $pesos);
        $this->assertSame('92.10', (string) $pesos->first()->peso_kg);
    }

    public function test_streak_conta_dias_completos_consecutivos(): void
    {
        $user = User::factory()->create();
        $s1 = $this->suplemento($user, ['horario' => '07:00']);
        $s2 = $this->suplemento($user, ['nome' => 'Outro', 'horario' => '12:00']);

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $dia) {
            foreach ([$s1, $s2] as $suplemento) {
                SaudeSuplementoCheckin::create([
                    'user_id' => $user->id,
                    'suplemento_id' => $suplemento->id,
                    'data' => $dia,
                ]);
            }
        }

        // Dia incompleto (só 1 de 2) não entra na sequência.
        SaudeSuplementoCheckin::create([
            'user_id' => $user->id,
            'suplemento_id' => $s1->id,
            'data' => '2026-08-01',
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson('/api/saude/overview?data=2026-08-05')
            ->assertOk()
            ->assertJsonPath('streak.atual', 3)
            ->assertJsonPath('streak.recorde', 3)
            ->assertJsonPath('progresso.tomados', 2)
            ->assertJsonPath('progresso.total', 2);
    }

    public function test_lembrete_envia_uma_vez_e_pula_usuario_sem_instancia(): void
    {
        $user = User::factory()->create();
        $this->suplemento($user, ['horario' => '00:01']); // vencido em qualquer hora do dia

        // Sem instância conectada: nada é enviado nem registrado.
        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldNotReceive('enviarParaMim');
        (new EnviarLembretesSuplementos)->handle($sender);
        $this->assertSame(0, SaudeLembrete::where('user_id', $user->id)->count());

        WhatsappInstancia::create([
            'user_id' => $user->id,
            'instance_name' => 'teste-saude',
            'phone' => '5511999999999',
            'status' => 'conectado',
        ]);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('enviarParaMim')->once()->andReturn(true);
        (new EnviarLembretesSuplementos)->handle($sender);
        $this->assertSame(1, SaudeLembrete::where('user_id', $user->id)->count());

        // Segundo ciclo no mesmo dia não reenvia.
        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldNotReceive('enviarParaMim');
        (new EnviarLembretesSuplementos)->handle($sender);
        $this->assertSame(1, SaudeLembrete::where('user_id', $user->id)->count());
    }

    private function suplemento(User $user, array $attrs = []): SaudeSuplemento
    {
        return SaudeSuplemento::create($attrs + [
            'user_id' => $user->id,
            'nome' => 'Suplemento',
            'horario' => '08:00',
            'ativo' => true,
            'posicao' => 1,
        ]);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }
}
