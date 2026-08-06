<?php

namespace App\Services\Saude;

use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
use App\Models\SaudeTreino;
use App\Models\SaudeTreinoSessao;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Traduz as atividades do Garmin para as tabelas do módulo Saúde.
 * Idempotente: reimportar a mesma janela não duplica nada.
 */
class GarminImportService
{
    /** typeKey do Garmin => modalidade de saude_cardio_sessoes. */
    private const MAPA_CARDIO = [
        'running' => 'corrida_rua',
        'street_running' => 'corrida_rua',
        'trail_running' => 'corrida_rua',
        'track_running' => 'corrida_rua',
        'treadmill_running' => 'esteira',
        'indoor_running' => 'esteira',
        'cycling' => 'bike',
        'road_biking' => 'bike',
        'mountain_biking' => 'bike',
        'gravel_cycling' => 'bike',
        'indoor_cycling' => 'bike',
        'virtual_ride' => 'bike',
        'walking' => 'caminhada',
        'indoor_walking' => 'caminhada',
        'hiking' => 'caminhada',
        'elliptical' => 'eliptico',
        'indoor_cardio' => 'outro',
        'cardio' => 'outro',
        'rowing' => 'outro',
        'indoor_rowing' => 'outro',
        'stair_climbing' => 'outro',
        'lap_swimming' => 'outro',
        'open_water_swimming' => 'outro',
    ];

    private const TIPOS_FORCA = ['strength_training', 'indoor_cardio_strength', 'yoga', 'pilates'];

    public function __construct(private readonly GarminService $garmin) {}

    /**
     * Importa a janela dos últimos `dias` dias.
     *
     * @return array{cardio: int, treinos: int, dias: int, ignorados: int}
     */
    public function sincronizar(?int $dias = null): array
    {
        $user = $this->usuario();

        $hoje = CarbonImmutable::now((string) config('saude.timezone'));
        $de = $hoje->subDays(($dias ?? (int) config('garmin.dias_janela')) - 1)->toDateString();
        $ate = $hoje->toDateString();

        $resultado = $this->importarAtividades($user, $de, $ate);
        $resultado['dias'] = $this->importarDias($user, $de, $ate);

        return $resultado;
    }

    /**
     * @return array{cardio: int, treinos: int, ignorados: int}
     */
    private function importarAtividades(User $user, string $de, string $ate): array
    {
        $cardio = 0;
        $treinos = 0;
        $ignorados = 0;

        foreach ($this->garmin->atividades($de, $ate) as $atividade) {
            $id = (int) ($atividade['id'] ?? 0);
            $tipo = (string) ($atividade['tipo'] ?? '');

            if ($id === 0 || $this->jaImportada($id)) {
                continue;
            }

            if (isset(self::MAPA_CARDIO[$tipo])) {
                $this->gravarCardio($user, $atividade, self::MAPA_CARDIO[$tipo]);
                $cardio++;

                continue;
            }

            if (in_array($tipo, self::TIPOS_FORCA, true)) {
                $this->gravarMusculacao($user, $atividade);
                $treinos++;

                continue;
            }

            Log::debug('Garmin: tipo de atividade ignorado.', ['tipo' => $tipo, 'id' => $id]);
            $ignorados++;
        }

        return ['cardio' => $cardio, 'treinos' => $treinos, 'ignorados' => $ignorados];
    }

    private function jaImportada(int $garminActivityId): bool
    {
        return SaudeCardioSessao::where('garmin_activity_id', $garminActivityId)->exists()
            || SaudeTreinoSessao::where('garmin_activity_id', $garminActivityId)->exists();
    }

    /** @param  array<string, mixed>  $atividade */
    private function gravarCardio(User $user, array $atividade, string $modalidade): void
    {
        [$data, $horario] = $this->dataEHorario($atividade);

        SaudeCardioSessao::create([
            'user_id' => $user->id,
            'data' => $data,
            'horario' => $horario,
            'nome' => $atividade['nome'] ?? null,
            'modalidade' => $modalidade,
            'duracao_min' => $this->minutos($atividade),
            'distancia_km' => isset($atividade['distancia_m'])
                ? round(((float) $atividade['distancia_m']) / 1000, 2)
                : null,
            'calorias' => isset($atividade['calorias']) ? (int) round((float) $atividade['calorias']) : null,
            'fc_media' => isset($atividade['fc_media']) ? (int) round((float) $atividade['fc_media']) : null,
            'fc_maxima' => isset($atividade['fc_maxima']) ? (int) round((float) $atividade['fc_maxima']) : null,
            'origem' => 'garmin',
            'garmin_activity_id' => (int) $atividade['id'],
        ]);
    }

    /**
     * Musculação respeita o UNIQUE(user_id, data) da tabela: dois treinos de
     * força no mesmo dia colapsam numa linha só, vencendo o último.
     *
     * @param  array<string, mixed>  $atividade
     */
    private function gravarMusculacao(User $user, array $atividade): void
    {
        [$data] = $this->dataEHorario($atividade);

        SaudeTreinoSessao::updateOrCreate(
            ['user_id' => $user->id, 'data' => $data],
            [
                'treino_id' => $this->fichaProvavel($user, $data),
                'duracao_min' => $this->minutos($atividade),
                'calorias' => isset($atividade['calorias']) ? (int) round((float) $atividade['calorias']) : null,
                'origem' => 'garmin',
                'garmin_activity_id' => (int) $atividade['id'],
            ],
        );
    }

    /**
     * O relógio não sabe se foi A ou B: usa a ficha alternada em relação à
     * última registrada, ou a primeira da lista quando não há histórico.
     */
    private function fichaProvavel(User $user, string $data): ?int
    {
        $fichas = SaudeTreino::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'musculacao')
            ->orderBy('posicao')
            ->pluck('id')
            ->all();

        if ($fichas === []) {
            return null;
        }

        $ultima = SaudeTreinoSessao::query()
            ->where('user_id', $user->id)
            ->where('data', '<', $data)
            ->orderByDesc('data')
            ->value('treino_id');

        $posicao = array_search($ultima, $fichas, true);

        return $posicao === false
            ? $fichas[0]
            : $fichas[($posicao + 1) % count($fichas)];
    }

    private function importarDias(User $user, string $de, string $ate): int
    {
        $importados = 0;

        for (
            $dia = CarbonImmutable::parse($de);
            $dia->lessThanOrEqualTo(CarbonImmutable::parse($ate));
            $dia = $dia->addDay()
        ) {
            $resumo = $this->garmin->dia($dia->toDateString());

            if ($resumo === null || ($resumo['calorias_ativas'] ?? null) === null) {
                continue;
            }

            SaudeDiaGarmin::updateOrCreate(
                ['user_id' => $user->id, 'data' => $dia->toDateString()],
                [
                    'passos' => $resumo['passos'] ?? null,
                    'calorias_ativas' => $resumo['calorias_ativas'],
                    'calorias_totais' => $resumo['calorias_totais'] ?? null,
                    'fc_repouso' => $resumo['fc_repouso'] ?? null,
                    'minutos_intensidade' => $resumo['minutos_intensidade'] ?? null,
                ],
            );
            $importados++;
        }

        return $importados;
    }

    /** @param  array<string, mixed>  $atividade */
    private function minutos(array $atividade): int
    {
        return max(1, (int) round(((float) ($atividade['duracao_seg'] ?? 0)) / 60));
    }

    /**
     * `inicio_local` já vem no fuso do relógio ("2026-08-05 19:19:47").
     *
     * @param  array<string, mixed>  $atividade
     * @return array{0: string, 1: string|null}
     */
    private function dataEHorario(array $atividade): array
    {
        $inicio = (string) ($atividade['inicio_local'] ?? '');

        if ($inicio === '') {
            return [CarbonImmutable::now((string) config('saude.timezone'))->toDateString(), null];
        }

        $momento = CarbonImmutable::parse($inicio);

        return [$momento->toDateString(), $momento->format('H:i')];
    }

    private function usuario(): User
    {
        $email = (string) config('garmin.user_email');

        $user = $email !== ''
            ? User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first()
            : null;

        if ($user === null) {
            throw new RuntimeException(
                'Defina GARMIN_USER_EMAIL com o e-mail do usuário que recebe as atividades do Garmin.',
            );
        }

        return $user;
    }
}
