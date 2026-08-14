<?php

namespace App\Services\Saude;

use App\Models\SaudeCardioDetalhe;
use App\Models\SaudeCardioSessao;
use App\Models\SaudeDiaGarmin;
use App\Models\SaudeMeta;
use App\Models\SaudePeso;
use App\Models\SaudeSono;
use App\Models\User;
use App\Support\Saude\NormasCardio;
use Carbon\CarbonImmutable;

/**
 * Monta os números da corrida: métricas exatas, leitura dos splits, comparativo
 * com as normas da faixa etária e o dossiê que vai para a IA.
 *
 * Tudo que dá para calcular é calculado aqui, em PHP. A IA recebe conclusões
 * numéricas prontas (ritmo caiu 8%, FC subiu 11 bpm, percentil 74) e escreve em
 * cima delas — assim ela não tem como inventar estatística.
 */
class SaudeCardioAnaliseService
{
    /** Quantas sessões da mesma modalidade entram na curva de evolução. */
    private const JANELA_EVOLUCAO = 12;

    public function __construct(
        private readonly SaudeNutricaoService $nutricao,
        private readonly SaudeCardioService $cardio,
    ) {}

    /**
     * Duração, distância e pace com a melhor precisão disponível.
     *
     * O detalhe traz os valores originais do relógio; a sessão só guarda minuto
     * inteiro, o que basta para o resumo semanal mas desloca o pace em até 30 s/km
     * numa corrida curta.
     *
     * @return array{duracao_seg: int|null, distancia_km: float|null, pace_seg_km: int|null, fonte: string}
     */
    public function metricas(SaudeCardioSessao $sessao, ?SaudeCardioDetalhe $detalhe): array
    {
        $exato = $detalhe?->duracao_seg !== null && $detalhe?->distancia_m !== null;

        $duracao = $exato ? (int) $detalhe->duracao_seg : (int) $sessao->duracao_min * 60;
        $distancia = $exato
            ? round((int) $detalhe->distancia_m / 1000, 3)
            : ($sessao->distancia_km !== null ? (float) $sessao->distancia_km : null);

        return [
            'duracao_seg' => $duracao,
            'distancia_km' => $distancia,
            'pace_seg_km' => $distancia > 0 ? (int) round($duracao / $distancia) : null,
            'fonte' => $exato ? 'garmin' : 'sessao',
        ];
    }

    /**
     * Leitura dos splits: como o ritmo se distribuiu e quanto a FC subiu para
     * sustentá-lo.
     *
     * Só considera voltas de pelo menos 400 m — a última volta quase sempre é
     * um pedaço de quilômetro, e o pace dela distorceria a comparação.
     *
     * @return array{voltas: list<array<string, mixed>>, pace_medio_seg_km: int, primeira_metade_seg_km: int|null, segunda_metade_seg_km: int|null, variacao_pct: float|null, veredito: string, deriva_fc_bpm: int|null, desvio_pace_seg: float}|null
     */
    public function analiseSplits(?SaudeCardioDetalhe $detalhe): ?array
    {
        $splits = $detalhe?->splits ?? [];

        if ($splits === []) {
            return null;
        }

        $voltas = [];

        foreach ($splits as $split) {
            $metros = (float) ($split['distancia_m'] ?? 0);
            $segundos = (float) ($split['duracao_seg'] ?? 0);

            if ($metros < 400 || $segundos <= 0) {
                continue;
            }

            $voltas[] = [
                'numero' => (int) ($split['numero'] ?? count($voltas) + 1),
                'distancia_m' => (int) round($metros),
                'duracao_seg' => (int) round($segundos),
                'pace_seg_km' => (int) round($segundos / ($metros / 1000)),
                'fc_media' => isset($split['fc_media']) ? (int) round((float) $split['fc_media']) : null,
                'fc_maxima' => isset($split['fc_maxima']) ? (int) round((float) $split['fc_maxima']) : null,
                'cadencia' => isset($split['cadencia']) ? (int) round((float) $split['cadencia']) : null,
                'elevacao_ganho_m' => isset($split['elevacao_ganho_m'])
                    ? (int) round((float) $split['elevacao_ganho_m'])
                    : null,
            ];
        }

        if ($voltas === []) {
            return null;
        }

        $paces = array_column($voltas, 'pace_seg_km');
        $paceMedio = (int) round(array_sum($paces) / count($paces));

        [$primeira, $segunda] = $this->metades($paces);
        [$fcPrimeira, $fcSegunda] = $this->metades(
            array_values(array_filter(array_column($voltas, 'fc_media'), fn ($fc) => $fc !== null)),
        );

        $variacao = ($primeira !== null && $segunda !== null && $primeira > 0)
            ? round(($segunda - $primeira) / $primeira * 100, 1)
            : null;

        return [
            'voltas' => $voltas,
            'pace_medio_seg_km' => $paceMedio,
            'primeira_metade_seg_km' => $primeira,
            'segunda_metade_seg_km' => $segunda,
            'variacao_pct' => $variacao,
            'veredito' => $this->vereditoPacing($variacao, $this->desvioPadrao($paces), $paceMedio),
            'deriva_fc_bpm' => ($fcPrimeira !== null && $fcSegunda !== null)
                ? $fcSegunda - $fcPrimeira
                : null,
            'desvio_pace_seg' => round($this->desvioPadrao($paces), 1),
        ];
    }

    /**
     * Acima desta fração da FC máxima, o esforço conta como "de prova".
     *
     * Numa prova de 5 a 10 km a FC média fica em 90–95% da máxima. Treino em
     * Z2/Z3 fica na casa dos 75–85%. O corte em 88% separa os dois sem exigir
     * que o usuário classifique o treino.
     */
    private const FRACAO_ESFORCO_MAXIMO = 0.88;

    /**
     * O comparativo com gente da mesma idade e sexo.
     *
     * Vão dois VO2max de propósito: o que o relógio estima (a partir de FC e
     * ritmo ao longo de semanas) e o que o desempenho DESTA corrida implica
     * pelo VDOT.
     *
     * O segundo só significa alguma coisa em esforço de prova: VDOT pressupõe
     * que a pessoa correu no limite. Num treino leve ele despenca — uma corrida
     * tranquila de 7:05/km devolve VDOT 25 para quem tem VO2max 49 — e
     * comparar isso com a tabela normativa diria que um atleta é sedentário.
     * Por isso `esforco_maximo` acompanha os números: quem consome decide se o
     * comparativo de desempenho vale ou se é só o retrato de um treino leve.
     */
    public function benchmarks(User $user, SaudeCardioSessao $sessao, ?SaudeCardioDetalhe $detalhe): array
    {
        $perfil = $this->nutricao->perfil($user);
        $meta = $perfil['meta'];
        $idade = $perfil['idade'];
        $sexo = $meta?->sexo;

        $metricas = $this->metricas($sessao, $detalhe);
        $km = $metricas['distancia_km'];
        $segundos = $metricas['duracao_seg'];

        // O VO2max do detalhe é o do dia da corrida; o da meta é o mais recente.
        $vo2Relogio = $detalhe?->vo2max !== null
            ? (float) $detalhe->vo2max
            : ($meta?->vo2max !== null ? (float) $meta->vo2max : null);

        $vo2Desempenho = NormasCardio::vo2maxEstimado($km, $segundos);

        $fcMaxima = $meta?->fc_maxima !== null
            ? (int) $meta->fc_maxima
            : NormasCardio::fcMaximaEstimada($idade);

        $fracaoFc = ($fcMaxima && $sessao->fc_media)
            ? round($sessao->fc_media / $fcMaxima, 3)
            : null;

        // Sem FC não dá para saber a intensidade: trata como submáximo, que é a
        // leitura conservadora (não promete desempenho que ninguém comprovou).
        $esforcoMaximo = $fracaoFc !== null && $fracaoFc >= self::FRACAO_ESFORCO_MAXIMO;

        return [
            'idade' => $idade,
            'sexo' => $sexo,
            'peso_kg' => $perfil['peso_atual'],
            'fc_maxima' => $fcMaxima,
            'fc_maxima_estimada' => $meta?->fc_maxima === null,
            'fc_limiar' => $meta?->fc_limiar !== null ? (int) $meta->fc_limiar : null,

            'esforco_maximo' => $esforcoMaximo,
            'esforco_fracao_fcmax' => $fracaoFc,

            'vo2max_relogio' => $vo2Relogio,
            'vo2max_desempenho' => $vo2Desempenho,
            'percentil' => NormasCardio::percentilVo2max($vo2Relogio, $sexo, $idade),
            // Só compara desempenho com a norma quando houve esforço de prova.
            'percentil_desempenho' => $esforcoMaximo
                ? NormasCardio::percentilVo2max($vo2Desempenho, $sexo, $idade)
                : null,
            'faixa_vo2max' => NormasCardio::faixaVo2max($sexo, $idade),
            'idade_fitness' => NormasCardio::idadeFitness($vo2Relogio, $sexo),

            'age_grade' => NormasCardio::ageGrade($km, $segundos, $sexo, $idade),
            'projecoes' => $this->projecoesDeProva($meta, $esforcoMaximo ? $vo2Desempenho : null),
            'projecao_peso' => NormasCardio::projecaoPorPeso(
                // Base é o VO2max do relógio: ele resume semanas de treino, e o
                // VDOT de um treino leve daria um ganho fantasioso.
                $vo2Relogio ?? $vo2Desempenho,
                $perfil['peso_atual'],
                $meta?->peso_meta_kg !== null ? (float) $meta->peso_meta_kg : null,
            ),
        ];
    }

    /**
     * Tempos previstos nas distâncias clássicas.
     *
     * Prioriza a previsão do Garmin, que reconcilia o histórico inteiro. Só cai
     * no VDOT desta corrida quando o Garmin não tem previsão E a corrida foi em
     * esforço de prova — projetar maratona a partir de um trote seria inventar.
     *
     * @return array{fonte: string, tempos: array<string, int>}|null
     */
    private function projecoesDeProva(?SaudeMeta $meta, ?float $vo2Desempenho): ?array
    {
        $doGarmin = array_filter([
            '5k' => $meta?->previsao_5k_seg,
            '10k' => $meta?->previsao_10k_seg,
            '21k' => $meta?->previsao_21k_seg,
            '42k' => $meta?->previsao_42k_seg,
        ], fn ($valor) => $valor !== null);

        if ($doGarmin !== []) {
            return ['fonte' => 'garmin', 'tempos' => array_map('intval', $doGarmin)];
        }

        if ($vo2Desempenho === null) {
            return null;
        }

        $tempos = [];

        foreach (['5k' => 5.0, '10k' => 10.0, '21k' => 21.0975, '42k' => 42.195] as $rotulo => $km) {
            $tempo = NormasCardio::tempoParaVo2max($vo2Desempenho, $km);

            if ($tempo !== null) {
                $tempos[$rotulo] = $tempo;
            }
        }

        return $tempos === [] ? null : ['fonte' => 'corrida', 'tempos' => $tempos];
    }

    /**
     * Sessões anteriores da mesma modalidade, da mais antiga para a mais nova.
     *
     * `batimentos_por_km` é o sinal de forma que aparece antes do pace: quando o
     * condicionamento melhora, o mesmo quilômetro custa menos batimentos, mesmo
     * que o relógio ainda não mostre ritmo melhor.
     *
     * @return list<array<string, mixed>>
     */
    public function evolucao(User $user, SaudeCardioSessao $sessao): array
    {
        $sessoes = SaudeCardioSessao::query()
            ->where('user_id', $user->id)
            ->where('modalidade', $sessao->modalidade)
            ->where('data', '<=', $sessao->data->toDateString())
            ->whereNotNull('distancia_km')
            ->where('distancia_km', '>', 0)
            ->with('detalhe')
            ->orderByDesc('data')
            ->orderByDesc('horario')
            ->orderByDesc('id')
            ->limit(self::JANELA_EVOLUCAO)
            ->get()
            ->reverse()
            ->values();

        return $sessoes->map(function (SaudeCardioSessao $item) use ($sessao) {
            $metricas = $this->metricas($item, $item->detalhe);
            $km = $metricas['distancia_km'];

            return [
                'id' => $item->id,
                'data' => $item->data->toDateString(),
                'distancia_km' => $km,
                'duracao_min' => $item->duracao_min,
                'pace_seg_km' => $metricas['pace_seg_km'],
                'fc_media' => $item->fc_media,
                'batimentos_por_km' => ($item->fc_media && $metricas['pace_seg_km'])
                    ? (int) round($item->fc_media * $metricas['pace_seg_km'] / 60)
                    : null,
                'atual' => $item->id === $sessao->id,
            ];
        })->all();
    }

    /**
     * O dossiê completo que vai para a IA — corrida, corpo, histórico, sono e
     * nutrição do dia. É o que separa "seu pace caiu" de "seu pace caiu porque
     * você dormiu 5 h e treinou em déficit de 700 kcal".
     */
    public function contexto(User $user, SaudeCardioSessao $sessao, ?SaudeCardioDetalhe $detalhe): array
    {
        $data = $sessao->data->toDateString();
        $perfil = $this->nutricao->perfil($user);
        $metricas = $this->metricas($sessao, $detalhe);

        return [
            'corrida' => [
                'data' => $data,
                'horario' => $sessao->horario,
                'nome' => $sessao->nome,
                'modalidade' => $sessao->modalidade,
                'distancia_km' => $metricas['distancia_km'],
                'duracao_seg' => $metricas['duracao_seg'],
                'pace_seg_km' => $metricas['pace_seg_km'],
                'fc_media' => $sessao->fc_media,
                'fc_maxima' => $sessao->fc_maxima,
                'calorias' => $sessao->calorias,
                'cadencia_media' => $detalhe?->cadencia_media,
                'passada_media_cm' => $detalhe?->passada_media_cm,
                'elevacao_ganho_m' => $detalhe?->elevacao_ganho_m,
                'elevacao_perda_m' => $detalhe?->elevacao_perda_m,
                'training_effect_aerobico' => $detalhe?->training_effect_aerobico,
                'training_effect_anaerobico' => $detalhe?->training_effect_anaerobico,
                'observacao' => $sessao->observacao,
            ],
            'splits' => $this->analiseSplits($detalhe),
            'zonas_fc' => $this->zonasComRotulo($detalhe),
            'corpo' => [
                'idade' => $perfil['idade'],
                'sexo' => $perfil['meta']?->sexo,
                'altura_cm' => $perfil['meta']?->altura_cm,
                'peso_kg' => $perfil['peso_atual'],
                'peso_meta_kg' => $perfil['meta']?->peso_meta_kg,
                'tendencia_peso' => $this->tendenciaPeso($user, $data),
            ],
            'benchmarks' => $this->benchmarks($user, $sessao, $detalhe),
            'historico' => [
                'sessoes' => array_slice($this->evolucao($user, $sessao), -8),
                'semana' => $this->cardio->resumo(
                    $user->id,
                    CarbonImmutable::parse($data)->subDays(6)->toDateString(),
                    $data,
                ),
                'quatro_semanas' => $this->cardio->resumo(
                    $user->id,
                    CarbonImmutable::parse($data)->subDays(27)->toDateString(),
                    $data,
                ),
            ],
            'recuperacao' => $this->recuperacao($user, $data),
            'nutricao' => $this->nutricaoDoDia($user, $data),
        ];
    }

    /** Tempo em cada zona, já em minutos e com a faixa de bpm. */
    private function zonasComRotulo(?SaudeCardioDetalhe $detalhe): ?array
    {
        $zonas = $detalhe?->zonas_fc ?? [];

        if ($zonas === []) {
            return null;
        }

        return array_values(array_map(fn ($zona) => [
            'zona' => (int) ($zona['zona'] ?? 0),
            'minutos' => round(((float) ($zona['segundos'] ?? 0)) / 60, 1),
            'fc_minima' => isset($zona['fc_minima']) ? (int) round((float) $zona['fc_minima']) : null,
        ], $zonas));
    }

    /** Sono da noite anterior e FC de repouso — o estado com que se chegou ao treino. */
    private function recuperacao(User $user, string $data): array
    {
        $noite = SaudeSono::where('user_id', $user->id)->where('data', $data)->first();
        $dia = SaudeDiaGarmin::where('user_id', $user->id)->where('data', $data)->first();

        $fcRepousoMedia = SaudeDiaGarmin::query()
            ->where('user_id', $user->id)
            ->whereBetween('data', [CarbonImmutable::parse($data)->subDays(29)->toDateString(), $data])
            ->whereNotNull('fc_repouso')
            ->avg('fc_repouso');

        return [
            'sono_min' => $noite?->duracao_min,
            'sono_score' => $noite?->score,
            'sono_profundo_min' => $noite?->profundo_min,
            'sono_rem_min' => $noite?->rem_min,
            'hrv_medio' => $noite?->hrv_medio,
            'estresse_medio' => $noite?->estresse_medio,
            'fc_repouso' => $dia?->fc_repouso,
            'fc_repouso_media_30d' => $fcRepousoMedia !== null ? (int) round((float) $fcRepousoMedia) : null,
            'passos_no_dia' => $dia?->passos,
        ];
    }

    /** O que foi comido no dia da corrida, contra as metas do módulo de calorias. */
    private function nutricaoDoDia(User $user, string $data): ?array
    {
        $resumo = $this->nutricao->resumoDia($user, $data);

        if (! $resumo['perfil_completo']) {
            return null;
        }

        return [
            'consumido' => $resumo['consumido'],
            'metas' => $resumo['metas'],
            'restante' => $resumo['restante'],
            'refeicoes' => $resumo['refeicoes']->map(fn ($refeicao) => [
                'horario' => $refeicao->horario,
                'tipo' => $refeicao->tipo,
                'nome' => $refeicao->nome,
                'calorias' => $refeicao->calorias,
                'proteinas_g' => $refeicao->proteinas_g,
            ])->all(),
        ];
    }

    /** Quanto o peso andou nos últimos 30 dias. */
    private function tendenciaPeso(User $user, string $data): ?array
    {
        $pesos = SaudePeso::query()
            ->where('user_id', $user->id)
            ->whereBetween('data', [CarbonImmutable::parse($data)->subDays(29)->toDateString(), $data])
            ->orderBy('data')
            ->get();

        if ($pesos->count() < 2) {
            return null;
        }

        $primeiro = (float) $pesos->first()->peso_kg;
        $ultimo = (float) $pesos->last()->peso_kg;

        return [
            'inicio_kg' => $primeiro,
            'fim_kg' => $ultimo,
            'variacao_kg' => round($ultimo - $primeiro, 2),
            'pesagens' => $pesos->count(),
        ];
    }

    /**
     * Média da primeira e da segunda metade da lista.
     *
     * @param  list<int>  $valores
     * @return array{0: int|null, 1: int|null}
     */
    private function metades(array $valores): array
    {
        if (count($valores) < 2) {
            return [null, null];
        }

        // Ímpar: a volta do meio fica de fora, para não pesar dos dois lados.
        $meio = intdiv(count($valores), 2);
        $primeira = array_slice($valores, 0, $meio);
        $segunda = array_slice($valores, -$meio);

        return [
            (int) round(array_sum($primeira) / count($primeira)),
            (int) round(array_sum($segunda) / count($segunda)),
        ];
    }

    /**
     * `irregular` vem antes das outras leituras: quando o desvio entre voltas
     * passa de 8% do pace médio, comparar metades não diz nada — é treino
     * intervalado ou trajeto com semáforo.
     */
    private function vereditoPacing(?float $variacaoPct, float $desvio, int $paceMedio): string
    {
        if ($paceMedio > 0 && $desvio / $paceMedio > 0.08) {
            return 'irregular';
        }

        if ($variacaoPct === null) {
            return 'indefinido';
        }

        return match (true) {
            $variacaoPct <= -2.0 => 'negative_split',
            $variacaoPct >= 2.0 => 'positive_split',
            default => 'even',
        };
    }

    /** @param  list<int>  $valores */
    private function desvioPadrao(array $valores): float
    {
        $n = count($valores);

        if ($n < 2) {
            return 0.0;
        }

        $media = array_sum($valores) / $n;
        $soma = array_sum(array_map(fn ($v) => ($v - $media) ** 2, $valores));

        return sqrt($soma / $n);
    }
}
