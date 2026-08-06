<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeSuplemento;
use App\Models\SaudeSuplementoCheckin;
use App\Services\Saude\SaudeStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuplementoCheckinController extends Controller
{
    /** Marca o suplemento como tomado no dia (idempotente). */
    public function store(Request $request, SaudeSuplemento $suplemento, SaudeStreakService $streak): JsonResponse
    {
        $this->authorizeOwnership($request, $suplemento);

        $userId = $request->user()->id;
        $data = $this->resolveData($request);

        $checkin = SaudeSuplementoCheckin::firstOrCreate(
            ['suplemento_id' => $suplemento->id, 'data' => $data],
            ['user_id' => $userId],
        );

        return response()->json([
            'checkin' => $checkin,
            'progresso' => $this->progressoDoDia($userId, $data),
            'streak' => $streak->streakFor($userId, $data),
        ], 201);
    }

    /** Desfaz o check-in do dia. */
    public function destroy(Request $request, SaudeSuplemento $suplemento, SaudeStreakService $streak): JsonResponse
    {
        $this->authorizeOwnership($request, $suplemento);

        $userId = $request->user()->id;
        $data = $this->resolveData($request);

        SaudeSuplementoCheckin::query()
            ->where('suplemento_id', $suplemento->id)
            ->where('data', $data)
            ->delete();

        return response()->json([
            'message' => 'Check-in desfeito.',
            'progresso' => $this->progressoDoDia($userId, $data),
            'streak' => $streak->streakFor($userId, $data),
        ]);
    }

    private function resolveData(Request $request): string
    {
        return $request->validate(['data' => ['nullable', 'date_format:Y-m-d']])['data']
            ?? now((string) config('saude.timezone'))->toDateString();
    }

    /** @return array{tomados: int, total: int} */
    private function progressoDoDia(int $userId, string $data): array
    {
        $ativosIds = SaudeSuplemento::query()
            ->where('user_id', $userId)
            ->where('ativo', true)
            ->pluck('id');

        $tomados = SaudeSuplementoCheckin::query()
            ->where('user_id', $userId)
            ->where('data', $data)
            ->whereIn('suplemento_id', $ativosIds)
            ->count();

        return ['tomados' => $tomados, 'total' => $ativosIds->count()];
    }

    private function authorizeOwnership(Request $request, SaudeSuplemento $suplemento): void
    {
        abort_unless((int) $suplemento->user_id === (int) $request->user()->id, 403);
    }
}
