<?php

namespace Tests\Feature;

use App\Services\Whatsapp\EvolutionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sessão zumbi: a Evolution guarda connectionStatus no banco e só o atualiza
 * quando o Baileys emite connection.update. Em 24/08/2026 o socket caiu sem
 * emitir, o campo ficou preso em 'open' e o painel exibiu "conectado" por
 * quatro dias enquanto o módulo não recebia webhook nem conseguia enviar.
 *
 * Os corpos de resposta abaixo são os que a Evolution v2.3.7 devolveu de fato
 * em produção durante o diagnóstico.
 */
class WhatsappSessaoZumbiTest extends TestCase
{
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

    private const INSTANCIA = [[
        'name' => 'raiz-u1-teste',
        'connectionStatus' => 'open',
        'ownerJid' => '5511993422549@s.whatsapp.net',
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('whatsapp.evolution.base_url', 'http://evolution:8080');
        config()->set('whatsapp.evolution.api_key', 'chave-de-teste');
        config()->set('whatsapp.evolution.numero_sonda', '5500000000000');
        config()->set('whatsapp.evolution.sonda_ttl_segundos', 60);
    }

    public function test_socket_vivo_mantem_a_instancia_conectada(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response(self::INSTANCIA, 200),
            '*/chat/whatsappNumbers/*' => Http::response(
                [['jid' => '5500000000000@s.whatsapp.net', 'exists' => false, 'number' => '5500000000000']],
                200,
            ),
        ]);

        $device = (new EvolutionService('raiz-u1-teste'))->fetchDevice();

        $this->assertTrue($device['sucesso']);
        $this->assertTrue($device['response']['connected']);
        $this->assertSame('open', $device['response']['session']);
        $this->assertSame('5511993422549', $device['response']['phone']);
    }

    public function test_open_com_socket_morto_e_reportado_como_zumbi(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response(self::INSTANCIA, 200),
            '*/chat/whatsappNumbers/*' => Http::response(self::CORPO_SOCKET_MORTO, 400),
        ]);

        $device = (new EvolutionService('raiz-u1-teste'))->fetchDevice();

        $this->assertTrue($device['sucesso']);
        $this->assertFalse($device['response']['connected'], 'sessão morta não pode ser reportada como conectada');
        $this->assertSame('zumbi', $device['response']['session']);
    }

    /**
     * Um soluço de rede ou a Evolution em apuros não provam que o socket caiu.
     * Rebaixar o status nesses casos daria falso alarme de "desconectado".
     */
    public function test_falha_transitoria_nao_rebaixa_o_status(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response(self::INSTANCIA, 200),
            '*/chat/whatsappNumbers/*' => Http::response(['message' => 'Internal Server Error'], 500),
        ]);

        $device = (new EvolutionService('raiz-u1-teste'))->fetchDevice();

        $this->assertTrue($device['response']['connected'], 'erro 5xx não deve ser lido como socket morto');
        $this->assertSame('open', $device['response']['session']);
    }

    /** Se a Evolution já admite que caiu, não há por que gastar uma sonda. */
    public function test_status_fechado_nao_dispara_sonda(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response(
                [['name' => 'raiz-u1-teste', 'connectionStatus' => 'close', 'ownerJid' => '5511993422549@s.whatsapp.net']],
                200,
            ),
            '*/chat/whatsappNumbers/*' => Http::response(self::CORPO_SOCKET_MORTO, 400),
        ]);

        $device = (new EvolutionService('raiz-u1-teste'))->fetchDevice();

        $this->assertFalse($device['response']['connected']);
        $this->assertSame('close', $device['response']['session']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'whatsappNumbers'));
    }

    /**
     * O motivo real vem aninhado em três formatos diferentes; sem varrer os
     * três o log registra só "HTTP 400" e esconde a causa.
     */
    public function test_erro_do_baileys_aparece_no_motivo(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(self::CORPO_SOCKET_MORTO, 400)]);

        $resp = (new EvolutionService('raiz-u1-teste'))->sendText('5511993422549', 'oi');

        $this->assertFalse($resp['sucesso']);
        $this->assertStringContainsString('Connection Closed', $resp['erro']);
    }

    public function test_erro_de_validacao_aparece_no_motivo(): void
    {
        Http::fake(['*/message/sendText/*' => Http::response(
            ['status' => 400, 'error' => 'Bad Request', 'response' => ['message' => ['número inválido']]],
            400,
        )]);

        $resp = (new EvolutionService('raiz-u1-teste'))->sendText('5511993422549', 'oi');

        $this->assertFalse($resp['sucesso']);
        $this->assertStringContainsString('número inválido', $resp['erro']);
    }

    /** A sonda é cacheada para não consultar o WhatsApp a cada leitura. */
    public function test_sonda_e_reaproveitada_dentro_do_ttl(): void
    {
        Http::fake([
            '*/instance/fetchInstances*' => Http::response(self::INSTANCIA, 200),
            '*/chat/whatsappNumbers/*' => Http::response(self::CORPO_SOCKET_MORTO, 400),
        ]);

        $svc = new EvolutionService('raiz-u1-teste');
        $svc->fetchDevice();
        $svc->fetchDevice();
        $svc->fetchDevice();

        Http::assertSentCount(4); // 3 fetchInstances + 1 sonda
    }
}
