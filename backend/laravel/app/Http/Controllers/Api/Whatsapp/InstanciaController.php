<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use App\Services\Whatsapp\EvolutionService;
use App\Services\Whatsapp\WhatsappConversaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestão da instância de WhatsApp do usuário (uma por usuário): criação na
 * Evolution, QR code / pareamento, status da sessão e preferências do módulo.
 */
class InstanciaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);

        return response()->json(['instancia' => $instancia]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apelido' => ['nullable', 'string', 'max:60'],
        ]);

        if ($this->instanciaDoUsuario($request) !== null) {
            return response()->json(['message' => 'Você já tem uma instância de WhatsApp configurada.'], 422);
        }

        $instanceName = 'raiz-u'.$request->user()->id.'-'.Str::lower(Str::random(6));
        $svc = new EvolutionService($instanceName);

        if (! $svc->configOk()) {
            return response()->json(['message' => 'Evolution API não configurada. Defina EVOLUTION_BASE_URL e EVOLUTION_API_KEY no .env.'], 422);
        }

        $resp = $svc->createInstance();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao criar a instância na Evolution: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        $webhook = $svc->setWebhook(EvolutionService::webhookUrlConfigurada());
        if (! ($webhook['sucesso'] ?? false)) {
            Log::warning('[whatsapp:instancia] webhook não configurado na criação: '.($webhook['erro'] ?? ''));
        }

        // Relatórios entram desligados: a coluna tem default(true), o que fazia
        // toda instância nova gastar duas chamadas de IA por dia sem ninguém
        // pedir. Os interruptores estão na própria tela.
        $instancia = WhatsappInstancia::create([
            'user_id' => $request->user()->id,
            'apelido' => $data['apelido'] ?? null,
            'instance_name' => $instanceName,
            'status' => 'conectando',
            'relatorio_diario_ativo' => false,
            'resumo_matinal_ativo' => false,
        ]);

        return response()->json(['instancia' => $instancia], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $data = $request->validate([
            'apelido' => ['nullable', 'string', 'max:60'],
            'gtd_ativo' => ['sometimes', 'boolean'],
            'calorias_foto_ativo' => ['sometimes', 'boolean'],
            'calorias_texto_ia' => ['sometimes', 'boolean'],
            'financeiro_ativo' => ['sometimes', 'boolean'],
            'aviso_apagadas_ativo' => ['sometimes', 'boolean'],
            'aviso_edicoes_ativo' => ['sometimes', 'boolean'],
            'relatorio_diario_ativo' => ['sometimes', 'boolean'],
            'resumo_matinal_ativo' => ['sometimes', 'boolean'],
        ]);

        $instancia->update($data);

        return response()->json(['instancia' => $instancia]);
    }

    /**
     * Remove a instância aqui E na Evolution.
     *
     * Antes isto era "melhor esforço": as duas chamadas à Evolution eram
     * tentadas e a linha local saía de qualquer jeito. Numa sessão zumbi as
     * duas falham — o logout precisa do socket que já caiu e o delete é
     * recusado enquanto connectionStatus for 'open' —, então o Laravel
     * esquecia a instância e a Evolution ficava com ela para sempre. Foi assim
     * que raiz-u1-bqrfz8 sobrou em 28/08/2026; com duas instâncias pareadas no
     * mesmo número brigando pelo slot de dispositivo vinculado, a sessão nova
     * durou cinco horas.
     *
     * Agora o restart vem primeiro (revive o socket e destrava o logout), e se
     * ainda assim a instância continuar de pé na Evolution a remoção local é
     * recusada em vez de gerar a órfã. `?forcar=true` para apagar mesmo assim,
     * ciente de que sobra lixo do outro lado.
     */
    public function destroy(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $forcar = $request->boolean('forcar');
        $svc = EvolutionService::forInstancia($instancia);
        $nome = $instancia->instance_name;

        try {
            // Um socket morto não faz logout. O restart o reergue com a
            // credencial que já está pareada, e aí a saída é limpa.
            if ($svc->socketMorto()) {
                $svc->restartInstance();
                sleep((int) config('whatsapp.evolution.espera_restart_segundos'));
            }
            $svc->logout();
            $svc->deleteInstance();
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:instancia] falha ao remover na Evolution: '.$e->getMessage());
        }

        if (! $forcar && $svc->existeNaEvolution()) {
            return response()->json([
                'message' => "A instância '{$nome}' não pôde ser removida da Evolution e continua lá. "
                    .'Apagá-la só daqui deixaria uma sessão órfã pareada no mesmo número, que briga pelo '
                    .'slot de dispositivo vinculado e derruba a sessão nova. Remova-a na Evolution antes, '
                    .'ou repita com forcar=true.',
            ], 409);
        }

        $instancia->delete();

        return response()->json(['message' => 'Instância removida com sucesso.']);
    }

    public function totalMensagens(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        return response()->json([
            'total' => WhatsappMensagem::where('instancia_id', $instancia->id)->count(),
        ]);
    }

    /**
     * Zera o histórico gravado sem mexer na instância: a sessão continua
     * conectada e o webhook segue apontado para cá, então a captação recomeça
     * na próxima mensagem que chegar. Não há como desfazer — a Evolution roda
     * com DATABASE_SAVE_DATA_HISTORIC=false e não guarda cópia.
     */
    public function limparMensagens(Request $request, WhatsappConversaService $conversas): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $apagadas = DB::transaction(function () use ($instancia) {
            $total = WhatsappMensagem::where('instancia_id', $instancia->id)->delete();

            // O resumo do chat é desnormalizado: sem zerar, as listas de
            // atenção continuariam cobrando resposta de mensagens que não
            // existem mais.
            WhatsappChat::where('instancia_id', $instancia->id)->update([
                'last_message_id' => null,
                'last_message_text' => null,
                'last_message_from_me' => false,
                'last_message_at' => null,
                'last_inbound_at' => null,
                'unread_count' => 0,
            ]);

            return $total;
        });

        // Fora da transação: fechar() apaga o anexo em disco, e disco não faz
        // rollback. Um fluxo pendente do chat-consigo-mesmo apontava para uma
        // mensagem que acabou de sumir.
        $conversas->fechar($conversas->paraInstancia($instancia));

        return response()->json([
            'message' => "{$apagadas} mensagem(ns) apagada(s). A captação recomeça a partir de agora.",
            'deleted' => $apagadas,
        ]);
    }

    public function qrcode(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->getQrcode();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao obter o QR code: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        $body = is_array($resp['response']) ? $resp['response'] : [];

        return response()->json([
            'base64' => (string) ($body['base64'] ?? ''),
            'code' => (string) ($body['code'] ?? ''),
            'pairing_code' => (string) ($body['pairingCode'] ?? ''),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->fetchDevice();
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json([
                'instancia' => $instancia,
                'connected' => false,
                'session' => 'indisponivel',
                'erro' => $resp['erro'] ?? 'Evolution indisponível',
            ]);
        }

        $device = $resp['response'];
        $novoStatus = $device['connected'] ? 'conectado' : ($device['session'] === 'connecting' ? 'conectando' : 'desconectado');

        $atualizacoes = ['status' => $novoStatus];
        if (($device['phone'] ?? '') !== '') {
            $atualizacoes['phone'] = $device['phone'];
        }
        $instancia->update($atualizacoes);

        return response()->json([
            'instancia' => $instancia,
            'connected' => (bool) $device['connected'],
            'session' => (string) $device['session'],
        ]);
    }

    /**
     * Reaponta o webhook da instância para esta aplicação (útil após restaurar
     * backup ou trocar de ambiente).
     */
    public function reconfigurarWebhook(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        abort_if($instancia === null, 404, 'Nenhuma instância configurada.');

        $resp = EvolutionService::forInstancia($instancia)->setWebhook(EvolutionService::webhookUrlConfigurada());
        if (! ($resp['sucesso'] ?? false)) {
            return response()->json(['message' => 'Falha ao configurar o webhook: '.($resp['erro'] ?? 'erro desconhecido')], 422);
        }

        return response()->json(['message' => 'Webhook reconfigurado com sucesso.']);
    }

    private function instanciaDoUsuario(Request $request): ?WhatsappInstancia
    {
        return WhatsappInstancia::where('user_id', $request->user()->id)->first();
    }
}
