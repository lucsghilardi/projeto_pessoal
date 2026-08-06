<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeCardioSessao;
use App\Models\SaudeMeta;
use App\Models\SaudePeso;
use App\Models\SaudeSuplemento;
use App\Models\SaudeSuplementoCheckin;
use App\Models\SaudeTreino;
use App\Models\SaudeTreinoSessao;
use App\Services\Saude\SaudeCardioService;
use App\Services\Saude\SaudeStreakService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaudeOverviewController extends Controller
{
    /** Payload agregado da tela "Check-in do dia". */
    public function overview(Request $request, SaudeStreakService $streak, SaudeCardioService $cardio): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']])['data']
            ?? now((string) config('saude.timezone'))->toDateString();

        $suplementos = SaudeSuplemento::query()
            ->where('user_id', $userId)
            ->where('ativo', true)
            ->orderBy('horario')
            ->orderBy('posicao')
            ->get();

        $tomadosIds = SaudeSuplementoCheckin::query()
            ->where('user_id', $userId)
            ->where('data', $data)
            ->pluck('suplemento_id')
            ->all();

        $suplementosDia = $suplementos
            ->map(fn (SaudeSuplemento $suplemento) => array_merge(
                $suplemento->toArray(),
                ['tomado' => in_array($suplemento->id, $tomadosIds, true)],
            ))
            ->values();

        $treinos = SaudeTreino::query()
            ->where('user_id', $userId)
            ->withCount('exercicios')
            ->orderBy('posicao')
            // `tipo` é usado pelo check-in para não oferecer ficha de cardio
            // como treino do dia (ela se registra em saude_cardio_sessoes).
            ->get(['id', 'nome', 'tipo', 'posicao']);

        $sessaoHoje = SaudeTreinoSessao::query()
            ->where('user_id', $userId)
            ->where('data', $data)
            ->with('treino:id,nome')
            ->first();

        $sessoesRecentes = SaudeTreinoSessao::query()
            ->where('user_id', $userId)
            ->where('data', '>=', CarbonImmutable::parse($data)->subDays(30)->toDateString())
            ->with('treino:id,nome')
            ->orderByDesc('data')
            ->get();

        $cardioRecentes = SaudeCardioSessao::query()
            ->where('user_id', $userId)
            ->where('data', '>=', CarbonImmutable::parse($data)->subDays(30)->toDateString())
            ->with('treino:id,nome')
            ->orderByDesc('data')
            ->orderByDesc('horario')
            ->get();

        $ultimoPeso = SaudePeso::query()
            ->where('user_id', $userId)
            ->orderByDesc('data')
            ->first();

        $pesosRecentes = SaudePeso::query()
            ->where('user_id', $userId)
            ->orderByDesc('data')
            ->limit(30)
            ->get(['id', 'data', 'peso_kg'])
            ->sortBy('data')
            ->values();

        return response()->json([
            'data' => $data,
            'suplementos' => $suplementosDia,
            'progresso' => [
                'tomados' => count(array_intersect($tomadosIds, $suplementos->pluck('id')->all())),
                'total' => $suplementos->count(),
            ],
            'streak' => $streak->streakFor($userId, $data),
            'treinos' => $treinos,
            'sessao_hoje' => $sessaoHoje,
            'sessoes_recentes' => $sessoesRecentes,
            'cardio_hoje' => $cardio->doDia($userId, $data),
            'cardio_recentes' => $cardioRecentes,
            'cardio_semana' => $cardio->resumo(
                $userId,
                CarbonImmutable::parse($data)->subDays(6)->toDateString(),
                $data,
            ),
            'peso' => [
                'ultimo' => $ultimoPeso,
                'recentes' => $pesosRecentes,
                'meta' => SaudeMeta::where('user_id', $userId)->first(),
            ],
        ]);
    }
}
