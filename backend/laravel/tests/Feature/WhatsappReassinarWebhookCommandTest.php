<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\EvolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `whatsapp:reassinar-webhook`: a Evolution congela a assinatura de eventos de
 * cada instância no que valia na criação, então evento novo só chega depois de
 * reassinar. Este comando é esse passo.
 */
class WhatsappReassinarWebhookCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.evolution.base_url' => 'http://evolution:8080',
            'whatsapp.evolution.api_key' => 'chave',
            'whatsapp.webhook.url' => 'http://backend:8000/api/whatsapp/webhook/evolution',
            'whatsapp.webhook.token' => 'tok en',
        ]);
    }

    public function test_reassina_todas_as_instancias_com_os_eventos_atuais(): void
    {
        Http::fake(['*' => Http::response(['webhook' => ['enabled' => true]], 200)]);
        $a = $this->instancia('inst-a');
        $b = $this->instancia('inst-b');

        $this->artisan('whatsapp:reassinar-webhook')
            ->expectsOutputToContain('MESSAGES_EDITED')
            ->expectsOutputToContain('2 instância(s) reassinada(s).')
            ->assertExitCode(0);

        foreach ([$a, $b] as $instancia) {
            Http::assertSent(function ($request) use ($instancia) {
                if ($request->url() !== "http://evolution:8080/webhook/set/{$instancia->instance_name}") {
                    return false;
                }

                $webhook = $request->data()['webhook'];

                // O token vai escapado na query string; é o que o
                // WebhookController confere antes de olhar o payload.
                return $webhook['url'] === 'http://backend:8000/api/whatsapp/webhook/evolution?token=tok+en'
                    && $webhook['events'] === EvolutionService::EVENTOS
                    && in_array('MESSAGES_EDITED', $webhook['events'], true)
                    && $webhook['base64'] === true;
            });
        }
    }

    public function test_limita_a_uma_instancia_com_a_opcao(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->instancia('inst-a');
        $this->instancia('inst-b');

        $this->artisan('whatsapp:reassinar-webhook', ['--instancia' => 'inst-b'])
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/webhook/set/inst-b'));
    }

    /** Falha silenciosa num deploy é pior que deploy vermelho. */
    public function test_falha_da_evolution_devolve_codigo_de_erro(): void
    {
        Http::fake(['*' => Http::response(['message' => 'instância não existe'], 404)]);
        $this->instancia('inst-a');

        $this->artisan('whatsapp:reassinar-webhook')
            ->expectsOutputToContain('inst-a')
            ->assertExitCode(1);
    }

    public function test_instancia_inexistente_nao_e_sucesso_silencioso(): void
    {
        Http::fake();

        $this->artisan('whatsapp:reassinar-webhook', ['--instancia' => 'nao-existe'])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    private function instancia(string $instanceName): WhatsappInstancia
    {
        return WhatsappInstancia::create([
            'user_id' => User::factory()->create()->id,
            'instance_name' => $instanceName,
            'status' => 'conectado',
        ]);
    }
}
