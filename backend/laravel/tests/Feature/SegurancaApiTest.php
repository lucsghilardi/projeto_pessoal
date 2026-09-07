<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Guardas da borda da API: autenticação do webhook da Evolution e os tetos de
 * requisição — que até aqui não existiam fora do login e da troca de senha.
 */
class SegurancaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.webhook.token' => 'tok-secreto']);
    }

    public function test_webhook_aceita_o_token_no_header(): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution', $this->evento(), [
            'X-Webhook-Token' => 'tok-secreto',
        ])->assertOk();
    }

    /**
     * Instâncias assinadas antes do header continuam chamando com a query
     * string; recusá-las derrubaria a entrada de mensagens sem avisar.
     */
    public function test_webhook_ainda_aceita_o_token_na_query_string(): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution?token=tok-secreto', $this->evento())
            ->assertOk();
    }

    public function test_webhook_sem_token_e_recusado(): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution', $this->evento())
            ->assertStatus(403);
    }

    public function test_webhook_com_token_errado_e_recusado(): void
    {
        $this->postJson('/api/whatsapp/webhook/evolution', $this->evento(), [
            'X-Webhook-Token' => 'chute',
        ])->assertStatus(403);

        $this->postJson('/api/whatsapp/webhook/evolution?token=chute', $this->evento())
            ->assertStatus(403);
    }

    /**
     * Sem token configurado a rota fica fechada — e não aberta, que seria o
     * jeito errado de falhar.
     */
    public function test_webhook_sem_token_configurado_recusa_qualquer_chamada(): void
    {
        config(['whatsapp.webhook.token' => '']);

        $this->postJson('/api/whatsapp/webhook/evolution', $this->evento(), [
            'X-Webhook-Token' => '',
        ])->assertStatus(403);
    }

    /**
     * As rotas que chamam a Anthropic são as caras: sem teto, uma sessão válida
     * queima crédito e prende workers do php-fpm.
     */
    public function test_rota_de_ia_para_no_teto_por_hora(): void
    {
        $user = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.Auth::guard('api')->login($user)];

        for ($i = 0; $i < 40; $i++) {
            $this->withHeaders($auth)
                ->postJson('/api/saude/refeicoes/analisar', [])
                ->assertStatus(422);
        }

        $this->withHeaders($auth)
            ->postJson('/api/saude/refeicoes/analisar', [])
            ->assertStatus(429);
    }

    /**
     * O teto da IA é por conta: quem estourou não pode calar o vizinho.
     */
    public function test_teto_de_ia_e_por_usuario(): void
    {
        $eu = User::factory()->create();
        $outro = User::factory()->create();

        for ($i = 0; $i < 41; $i++) {
            $this->withHeaders(['Authorization' => 'Bearer '.Auth::guard('api')->login($eu)])
                ->postJson('/api/saude/refeicoes/analisar', []);
        }

        $this->withHeaders(['Authorization' => 'Bearer '.Auth::guard('api')->login($outro)])
            ->postJson('/api/saude/refeicoes/analisar', [])
            ->assertStatus(422);
    }

    public function test_limitador_geral_da_api_esta_registrado(): void
    {
        $limitador = RateLimiter::limiter('api');

        $this->assertNotNull($limitador, 'O throttleApi() do bootstrap/app.php depende deste limitador.');

        $user = User::factory()->create();
        $painel = Request::create('/api/me');
        $painel->setUserResolver(fn () => $user);

        $this->assertSame(180, $limitador($painel)->maxAttempts);

        // O webhook chega em rajada (uma entrega por mensagem e outra por
        // atualização de status) e tem balde próprio, para não competir com o
        // painel nem ser calado por ele.
        $webhook = Request::create('/api/whatsapp/webhook/evolution');

        $this->assertSame(600, $limitador($webhook)->maxAttempts);
    }

    /**
     * @return array<string, mixed>
     */
    private function evento(): array
    {
        // Instância desconhecida: o webhook responde 200 e ignora. Basta para
        // provar que a autenticação passou — que é o que este teste cobre.
        return [
            'event' => 'messages.upsert',
            'instance' => 'instancia-que-nao-existe',
            'data' => [],
        ];
    }
}
