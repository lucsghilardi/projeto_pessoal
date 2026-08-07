<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappRelatorio;
use App\Models\WhatsappSugestao;
use App\Services\Whatsapp\WhatsappRelatorioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Relatórios gerados pela IA (fim de dia e briefing matinal).
 */
class RelatorioController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $relatorios = WhatsappRelatorio::where('user_id', $request->user()->id)
            ->orderByDesc('referencia_data')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json(['relatorios' => $relatorios]);
    }

    public function show(Request $request, WhatsappRelatorio $relatorio): JsonResponse
    {
        abort_unless((int) $relatorio->user_id === (int) $request->user()->id, 403, 'Este relatório não pertence a você.');

        return response()->json(['relatorio' => $this->comSugestoesVisiveis($relatorio, $request)]);
    }

    /**
     * Gera (ou regenera) o relatório agora, sob demanda. Chamada síncrona —
     * mesma janela de timeout do lançamento por IA do financeiro.
     */
    public function gerar(Request $request, WhatsappRelatorioService $service): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:diario,matinal'],
            'enviar' => ['sometimes', 'boolean'],
        ]);

        try {
            $relatorio = $service->gerarEEnviar(
                $request->user(),
                $data['tipo'],
                (bool) ($data['enviar'] ?? true),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['relatorio' => $this->comSugestoesVisiveis($relatorio, $request)], 201);
    }

    /** Sugestão descartada não aparece nem no histórico do relatório. */
    private function comSugestoesVisiveis(WhatsappRelatorio $relatorio, Request $request): WhatsappRelatorio
    {
        $relatorio->load('sugestoes');

        return $relatorio->setRelation(
            'sugestoes',
            WhatsappSugestao::semDescartadas($relatorio->sugestoes, $request->user()->id),
        );
    }
}
