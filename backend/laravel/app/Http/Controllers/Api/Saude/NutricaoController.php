<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Services\Saude\SaudeNutricaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NutricaoController extends Controller
{
    private const DIAS_HISTORICO = 14;

    /** Payload da página de calorias: dia + metas + restante + histórico. */
    public function overview(Request $request, SaudeNutricaoService $nutricao): JsonResponse
    {
        $filters = $request->validate([
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $data = $filters['data'] ?? now(config('saude.timezone'))->toDateString();
        $resumo = $nutricao->resumoDia($request->user(), $data);

        return response()->json([
            'data' => $resumo['data'],
            'perfil_completo' => $resumo['perfil_completo'],
            'metas' => $resumo['metas'],
            'consumido' => $resumo['consumido'],
            'restante' => $resumo['restante'],
            'refeicoes' => $resumo['refeicoes'],
            'gasto_exercicio' => $resumo['gasto_exercicio'],
            'historico' => $this->historico((int) $request->user()->id, $data),
        ]);
    }

    public function projecao(Request $request, SaudeNutricaoService $nutricao): JsonResponse
    {
        // Envelope para evitar o corpo "{}" que o JsonResponse gera com null.
        return response()->json([
            'projecao' => $nutricao->projecao($request->user()),
        ]);
    }

    /**
     * Somatório diário dos últimos N dias (dias sem registro entram zerados).
     * O sono da noite entra junto para dar para cruzar noite ruim com adesão
     * à dieta — e fica `null`, não zero: dia sem medição não é noite em claro.
     *
     * @return list<array{data: string, calorias: int, proteinas_g: float, sono_min: int|null}>
     */
    private function historico(int $userId, string $ate): array
    {
        $inicio = Carbon::parse($ate)->subDays(self::DIAS_HISTORICO - 1)->toDateString();

        $porDia = DB::table('saude_refeicoes')
            ->where('user_id', $userId)
            ->whereBetween('data', [$inicio, $ate])
            ->groupBy('data')
            ->selectRaw('data, SUM(calorias) as calorias, SUM(proteinas_g) as proteinas_g')
            ->get()
            ->keyBy(fn ($linha) => substr((string) $linha->data, 0, 10));

        $sonoPorDia = DB::table('saude_sonos')
            ->where('user_id', $userId)
            ->whereBetween('data', [$inicio, $ate])
            ->get(['data', 'duracao_min'])
            ->keyBy(fn ($linha) => substr((string) $linha->data, 0, 10));

        $historico = [];
        for ($i = self::DIAS_HISTORICO - 1; $i >= 0; $i--) {
            $dia = Carbon::parse($ate)->subDays($i)->toDateString();
            $linha = $porDia->get($dia);
            $sono = $sonoPorDia->get($dia);
            $historico[] = [
                'data' => $dia,
                'calorias' => (int) ($linha->calorias ?? 0),
                'proteinas_g' => round((float) ($linha->proteinas_g ?? 0), 1),
                'sono_min' => $sono !== null ? (int) $sono->duracao_min : null,
            ];
        }

        return $historico;
    }
}
