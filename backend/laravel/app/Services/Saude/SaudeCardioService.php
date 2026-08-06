<?php

namespace App\Services\Saude;

use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
use App\Models\SaudeTreinoSessao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sessões de cardio: leitura do dia, resumo por período e o gasto calórico
 * usado pelo TDEE dinâmico do SaudeNutricaoService.
 */
class SaudeCardioService
{
    /**
     * MET por modalidade. O cálculo abaixo usa (MET - 1) porque o metabolismo
     * basal daquele intervalo já está contido no TDEE — usar o MET bruto
     * infla o gasto em ~10%.
     */
    private const MET = [
        'corrida_rua' => 9.8,
        'esteira' => 8.5,
        'bike' => 7.5,
        'eliptico' => 5.0,
        'caminhada' => 3.5,
        'outro' => 6.0,
    ];

    /** @return Collection<int, SaudeCardioSessao> */
    public function doDia(int $userId, string $data): Collection
    {
        return SaudeCardioSessao::query()
            ->where('user_id', $userId)
            ->where('data', $data)
            ->with('treino:id,nome')
            ->orderBy('horario')
            ->orderBy('id')
            ->get();
    }

    /**
     * Totais do período, para os cards de resumo semanal.
     *
     * @return array{sessoes: int, minutos: int, distancia_km: float, calorias: int, por_modalidade: array<string, int>}
     */
    public function resumo(int $userId, string $de, string $ate): array
    {
        $sessoes = SaudeCardioSessao::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$de, $ate])
            ->get();

        return [
            'sessoes' => $sessoes->count(),
            'minutos' => (int) $sessoes->sum('duracao_min'),
            'distancia_km' => round($sessoes->sum(fn (SaudeCardioSessao $s) => (float) $s->distancia_km), 2),
            'calorias' => (int) $sessoes->sum('calorias'),
            'por_modalidade' => $sessoes
                ->groupBy('modalidade')
                ->map(fn (Collection $grupo) => (int) $grupo->sum('duracao_min'))
                ->all(),
        ];
    }

    /**
     * Gasto de exercício do dia, em ordem de confiança:
     *   1. calorias ativas medidas pelo relógio (cobrem o dia inteiro, inclusive
     *      caminhada fora de atividade registrada);
     *   2. calorias informadas nas sessões de cardio e musculação;
     *   3. estimativa por MET, para as sessões que não trouxeram calorias.
     */
    public function caloriasDoDia(int $userId, string $data, ?float $pesoKg): int
    {
        return $this->caloriasPorDia($userId, $data, $data, $pesoKg)[$data] ?? 0;
    }

    /**
     * Média diária de gasto no período — usada pela projeção de peso, que não
     * pode oscilar com o treino de hoje.
     */
    public function mediaDiariaCalorias(int $userId, string $de, string $ate, ?float $pesoKg): int
    {
        $porDia = $this->caloriasPorDia($userId, $de, $ate, $pesoKg);

        $dias = max(1, CarbonImmutable::parse($de)->diffInDays(CarbonImmutable::parse($ate)) + 1);

        return (int) round(array_sum($porDia) / $dias);
    }

    /**
     * Gasto de cada dia do período, numa varredura só (a projeção olha 28 dias).
     *
     * @return array<string, int> data "Y-m-d" => kcal
     */
    private function caloriasPorDia(int $userId, string $de, string $ate, ?float $pesoKg): array
    {
        $medidoPeloRelogio = SaudeDiaGarmin::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$de, $ate])
            ->whereNotNull('calorias_ativas')
            ->pluck('calorias_ativas', 'data')
            ->all();

        $porDia = [];

        $cardio = SaudeCardioSessao::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$de, $ate])
            ->get();

        foreach ($cardio as $sessao) {
            $dia = $sessao->data->toDateString();
            $porDia[$dia] = ($porDia[$dia] ?? 0) + ($sessao->calorias
                ?? $this->estimarCalorias($sessao->modalidade, (int) $sessao->duracao_min, $pesoKg));
        }

        $musculacao = SaudeTreinoSessao::query()
            ->where('user_id', $userId)
            ->whereBetween('data', [$de, $ate])
            ->get();

        foreach ($musculacao as $sessao) {
            $dia = $sessao->data->toDateString();
            $porDia[$dia] = ($porDia[$dia] ?? 0) + ($sessao->calorias
                ?? $this->estimarCalorias('outro', (int) ($sessao->duracao_min ?? 0), $pesoKg));
        }

        // O medido pelo relógio ganha do somado sessão a sessão.
        foreach ($medidoPeloRelogio as $dia => $calorias) {
            $porDia[(string) $dia] = (int) $calorias;
        }

        return array_map(intval(...), $porDia);
    }

    /** kcal = (MET - 1) × 3,5 × peso / 200 × minutos. Sem peso não dá para estimar. */
    public function estimarCalorias(string $modalidade, int $minutos, ?float $pesoKg): int
    {
        if ($pesoKg === null || $pesoKg <= 0 || $minutos <= 0) {
            return 0;
        }

        $met = self::MET[$modalidade] ?? self::MET['outro'];

        return (int) round(($met - 1) * 3.5 * $pesoKg / 200 * $minutos);
    }
}
