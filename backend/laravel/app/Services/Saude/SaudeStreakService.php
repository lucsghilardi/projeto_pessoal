<?php

namespace App\Services\Saude;

use App\Models\SaudeSuplemento;
use App\Models\SaudeSuplementoCheckin;
use Carbon\CarbonImmutable;

/**
 * Streak do check-in de suplementos, calculada direto dos dados — sem estado
 * persistido e independente da gamificação das tarefas. Um dia conta como
 * completo quando o nº de check-ins de suplementos ativos alcança o nº de
 * suplementos ativos hoje; editar o elenco reavalia o histórico sob a regra
 * nova (aceitável: o objetivo é motivação, não auditoria).
 */
class SaudeStreakService
{
    /** @return array{atual: int, recorde: int} */
    public function streakFor(int $userId, string $hoje): array
    {
        $total = SaudeSuplemento::query()
            ->where('user_id', $userId)
            ->where('ativo', true)
            ->count();

        if ($total === 0) {
            return ['atual' => 0, 'recorde' => 0];
        }

        $diasCompletos = SaudeSuplementoCheckin::query()
            ->where('user_id', $userId)
            ->whereHas('suplemento', fn ($q) => $q->where('ativo', true))
            ->groupBy('data')
            ->havingRaw('count(*) >= ?', [$total])
            ->orderBy('data')
            ->pluck('data')
            ->map(fn ($dia) => CarbonImmutable::parse($dia)->toDateString());

        if ($diasCompletos->isEmpty()) {
            return ['atual' => 0, 'recorde' => 0];
        }

        // Recorde: maior sequência de dias consecutivos do histórico.
        $recorde = 0;
        $sequencia = 0;
        $anterior = null;
        foreach ($diasCompletos as $dia) {
            $sequencia = ($anterior !== null && CarbonImmutable::parse($anterior)->addDay()->toDateString() === $dia)
                ? $sequencia + 1
                : 1;
            $recorde = max($recorde, $sequencia);
            $anterior = $dia;
        }

        // Atual: sequência terminando hoje — ou ontem, porque o dia em
        // andamento ainda incompleto não quebra a corrente.
        $set = array_flip($diasCompletos->all());
        $cursor = CarbonImmutable::parse($hoje);
        if (! isset($set[$cursor->toDateString()])) {
            $cursor = $cursor->subDay();
        }

        $atual = 0;
        while (isset($set[$cursor->toDateString()])) {
            $atual++;
            $cursor = $cursor->subDay();
        }

        return ['atual' => $atual, 'recorde' => max($recorde, $atual)];
    }
}
