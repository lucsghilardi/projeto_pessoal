<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeCardioAnalise;
use App\Models\SaudeCardioSessao;
use App\Services\Saude\GarminImportService;
use App\Services\Saude\GarminService;
use App\Services\Saude\SaudeCardioAI;
use App\Services\Saude\SaudeCardioAnaliseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Análise da corrida pela IA.
 *
 * Chamada síncrona, na mesma janela de timeout do lançamento por IA do
 * financeiro — o nginx já está afinado em 180 s para isso. O resultado fica
 * gravado, então reabrir a corrida não gasta token: só o botão "Refazer" chama
 * a IA de novo.
 */
class CardioAnaliseController extends Controller
{
    public function gerar(
        Request $request,
        SaudeCardioSessao $cardio,
        SaudeCardioAnaliseService $contexto,
        SaudeCardioAI $ia,
        GarminImportService $import,
        GarminService $garmin,
    ): JsonResponse {
        abort_unless((int) $cardio->user_id === (int) $request->user()->id, 403);

        $dados = $request->validate([
            'forcar' => ['nullable', 'boolean'],
        ]);

        $existente = SaudeCardioAnalise::where('cardio_sessao_id', $cardio->id)->first();

        if ($existente !== null && ! ($dados['forcar'] ?? false)) {
            return response()->json($existente);
        }

        // Uma análise por vez: a chamada leva dezenas de segundos e o botão
        // pode ser clicado duas vezes antes da primeira resposta voltar.
        $chave = "saude:cardio:analise:{$cardio->id}";

        if (! Cache::add($chave, now()->toIso8601String(), now()->addMinutes(5))) {
            return response()->json(['message' => 'Já existe uma análise em andamento para esta corrida.'], 409);
        }

        try {
            $detalhe = $this->detalheDisponivel($cardio, $import, $garmin->ehDono($request->user()));

            $analise = $ia->analisar($contexto->contexto($request->user(), $cardio, $detalhe));

            $registro = SaudeCardioAnalise::updateOrCreate(
                ['cardio_sessao_id' => $cardio->id],
                [
                    'user_id' => $request->user()->id,
                    'dados' => $analise,
                    'modelo' => (string) config('saude.cardio.model'),
                    'gerado_em' => now(),
                ],
            );
        } catch (RuntimeException $erro) {
            return response()->json(['message' => $erro->getMessage()], 422);
        } finally {
            Cache::forget($chave);
        }

        return response()->json($registro);
    }

    /**
     * Garante o melhor contexto possível antes de gastar a chamada de IA: sem
     * splits a análise fica genérica. Mas Garmin fora do ar não pode impedir a
     * análise — nesse caso ela sai só com o resumo da sessão. O mesmo vale para
     * quem não é o dono da conta Garmin: usa o que já estiver gravado e segue.
     */
    private function detalheDisponivel(
        SaudeCardioSessao $cardio,
        GarminImportService $import,
        bool $podeBuscarNoGarmin,
    ) {
        $detalhe = $cardio->detalhe()->first();

        if ($detalhe !== null || $cardio->garmin_activity_id === null || ! $podeBuscarNoGarmin) {
            return $detalhe;
        }

        try {
            return $import->importarDetalhe($cardio);
        } catch (\Throwable $erro) {
            Log::info('Análise de cardio sem detalhe do Garmin.', [
                'sessao' => $cardio->id,
                'erro' => $erro->getMessage(),
            ]);

            return null;
        }
    }
}
