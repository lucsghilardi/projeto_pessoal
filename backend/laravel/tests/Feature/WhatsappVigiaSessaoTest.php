<?php

namespace Tests\Feature;

use App\Jobs\VerificarSessaoWhatsapp;
use App\Models\User;
use App\Models\WhatsappInstancia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vigia da sessão: sonda o socket sozinho e reergue o que morreu calado.
 *
 * A detecção de zumbi já existia, mas só rodava quando alguém abria a tela do
 * painel — em 28/08/2026 a sessão ficou dois dias parada porque ninguém abriu.
 * Os corpos abaixo são os que a Evolution v2.3.7 devolveu em produção durante o
 * diagnóstico de 30/08/2026.
 */
class WhatsappVigiaSessaoTest extends TestCase
{
    use RefreshDatabase;

    /** Resposta real do socket morto: 428 embrulhado num Boom, HTTP 400. */
    private const CORPO_SOCKET_MORTO = [
        'data' => null,
        'isBoom' => true,
        'isServer' => false,
        'output' => [
            'statusCode' => 428,
            'payload' => [
                'statusCode' => 428,
                'error' => 'Precondition Required',
                'message' => 'Connection Closed',
            ],
            'headers' => [],
        ],
    ];

    private const CORPO_SOCKET_VIVO = [
        ['jid' => '5500000000000@s.whatsapp.net', 'exists' => false, 'number' => '5500000000000'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('whatsapp.evolution.base_url', 'http://evolution:8080');
        config()->set('whatsapp.evolution.api_key', 'chave-de-teste');
        config()->set('whatsapp.evolution.numero_sonda', '5500000000000');
        config()->set('whatsapp.evolution.sonda_ttl_segundos', 60);
        // Os testes não devem dormir os 20s de produção.
        config()->set('whatsapp.evolution.espera_restart_segundos', 0);
    }

    private function instancia(string $status = 'conectado'): WhatsappInstancia
    {
        return WhatsappInstancia::create([
            'user_id' => User::factory()->create()->id,
            'instance_name' => 'raiz-u1-teste',
            'phone' => '5511993422549',
            'status' => $status,
        ]);
    }

    public function test_socket_vivo_nao_dispara_restart(): void
    {
        $instancia = $this->instancia();
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response(self::CORPO_SOCKET_VIVO, 200)]);

        (new VerificarSessaoWhatsapp)->handle();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/instance/restart/'));
        $this->assertSame('conectado', $instancia->fresh()->status);
    }

    public function test_socket_morto_reinicia_a_instancia_e_marca_reconectado(): void
    {
        $instancia = $this->instancia();

        // Primeira sonda encontra o socket morto; depois do restart, vivo.
        $sondas = [
            Http::response(self::CORPO_SOCKET_MORTO, 400),
            Http::response(self::CORPO_SOCKET_VIVO, 200),
        ];
        Http::fake([
            '*/chat/whatsappNumbers/*' => Http::sequence($sondas),
            '*/instance/restart/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
        ]);

        (new VerificarSessaoWhatsapp)->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/instance/restart/raiz-u1-teste')
            && $req->method() === 'POST');
        $this->assertSame('conectado', $instancia->fresh()->status);
    }

    public function test_socket_que_nao_volta_fica_marcado_desconectado(): void
    {
        $instancia = $this->instancia();

        Http::fake([
            '*/chat/whatsappNumbers/*' => Http::response(self::CORPO_SOCKET_MORTO, 400),
            '*/instance/restart/*' => Http::response(['instance' => ['state' => 'connecting']], 200),
        ]);

        (new VerificarSessaoWhatsapp)->handle();

        $this->assertSame('desconectado', $instancia->fresh()->status);
    }

    /**
     * Um 5xx ou um timeout não provam socket morto — só o 428 explícito prova.
     * Sem esta guarda, um soluço de rede na Evolution reiniciaria a sessão boa.
     */
    public function test_falha_generica_da_evolution_nao_reinicia_a_sessao(): void
    {
        $instancia = $this->instancia();
        Http::fake(['*/chat/whatsappNumbers/*' => Http::response(['erro' => 'indisponivel'], 503)]);

        (new VerificarSessaoWhatsapp)->handle();

        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/instance/restart/'));
        $this->assertSame('conectado', $instancia->fresh()->status);
    }
}
