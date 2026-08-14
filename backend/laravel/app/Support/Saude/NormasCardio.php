<?php

namespace App\Support\Saude;

/**
 * Normas populacionais de corrida — o "comparativo com gente da minha idade".
 *
 * Não existe API pública e gratuita que devolva pace ou percentil por idade e
 * peso. O que existe são tabelas normativas publicadas, e é isso que está aqui:
 * tudo estático, sem I/O, para poder ser testado sozinho e nunca depender de
 * rede no meio de uma requisição.
 *
 * Sobre peso: não há dataset de percentil por peso, e inventar um seria mentira.
 * O peso entra pela física — VO2max é medido em ml por kg por minuto, então
 * perder massa levanta o número mesmo sem ganho de condicionamento. É o que
 * `projecaoPorPeso()` calcula.
 */
class NormasCardio
{
    /**
     * VO2max (ml/kg/min) por percentil e faixa etária.
     * Fonte: The Cooper Institute, Aerobics Center Longitudinal Study, conforme
     * ACSM's Guidelines for Exercise Testing and Prescription, 11ª edição, Tab. 4.7.
     *
     * [idade_inicial => [percentil => valor]]
     */
    private const VO2MAX = [
        'M' => [
            20 => [5 => 29.0, 10 => 32.1, 25 => 40.1, 50 => 48.0, 75 => 55.2, 90 => 61.8, 95 => 66.3],
            30 => [5 => 27.2, 10 => 30.2, 25 => 35.9, 50 => 42.4, 75 => 49.2, 90 => 56.5, 95 => 59.8],
            40 => [5 => 24.2, 10 => 26.8, 25 => 31.9, 50 => 37.8, 75 => 45.0, 90 => 52.1, 95 => 55.6],
            50 => [5 => 20.9, 10 => 22.8, 25 => 27.1, 50 => 32.6, 75 => 39.7, 90 => 45.6, 95 => 50.7],
            60 => [5 => 17.4, 10 => 19.8, 25 => 23.7, 50 => 28.2, 75 => 34.5, 90 => 40.3, 95 => 43.0],
            70 => [5 => 16.3, 10 => 17.1, 25 => 20.4, 50 => 24.4, 75 => 30.4, 90 => 36.6, 95 => 39.7],
        ],
        'F' => [
            20 => [5 => 21.7, 10 => 23.9, 25 => 30.5, 50 => 37.6, 75 => 44.7, 90 => 51.3, 95 => 56.0],
            30 => [5 => 19.0, 10 => 20.9, 25 => 25.3, 50 => 30.2, 75 => 36.1, 90 => 41.4, 95 => 45.8],
            40 => [5 => 17.0, 10 => 18.8, 25 => 22.1, 50 => 26.7, 75 => 32.4, 90 => 38.4, 95 => 41.7],
            50 => [5 => 16.0, 10 => 17.3, 25 => 19.9, 50 => 23.4, 75 => 27.6, 90 => 32.0, 95 => 35.9],
            60 => [5 => 13.4, 10 => 14.6, 25 => 17.2, 50 => 20.0, 75 => 23.8, 90 => 27.0, 95 => 29.4],
            70 => [5 => 13.1, 10 => 13.6, 25 => 15.6, 50 => 18.3, 75 => 20.8, 90 => 23.1, 95 => 24.1],
        ],
    ];

    /**
     * Tempo-padrão (100%) em segundos por idade, para 5 km e 10 km de rua.
     * Fonte: Alan Jones, tabelas WMA de estrada 2025 (aprovadas pelo USATF
     * Masters LDR Council). Aferido: o padrão masculino de 10 km dá 1584 s =
     * 26:24, exatamente o recorde mundial da distância.
     *
     * Só 5 e 10 km: são as distâncias de referência mais próximas do que se
     * corre no dia a dia, e `riegel()` normaliza qualquer distância torta até
     * uma delas. Meia e maratona ficariam extrapolando longe demais do
     * histórico real para valer a pena.
     *
     * [sexo => [distancia_km => [idade => segundos]]]
     */
    private const PADRAO_ESTRADA = [
        'M' => [
            5 => [30 => 770, 35 => 783, 40 => 812, 45 => 842, 50 => 877, 55 => 913,
                60 => 952, 65 => 995, 70 => 1046, 75 => 1123, 80 => 1242],
            10 => [30 => 1584, 35 => 1598, 40 => 1644, 45 => 1710, 50 => 1783, 55 => 1861,
                60 => 1947, 65 => 2041, 70 => 2145, 75 => 2286, 80 => 2514],
        ],
        'F' => [
            5 => [30 => 837, 35 => 851, 40 => 878, 45 => 919, 50 => 971, 55 => 1029,
                60 => 1094, 65 => 1169, 70 => 1255, 75 => 1353, 80 => 1479],
            10 => [30 => 1730, 35 => 1751, 40 => 1794, 45 => 1860, 50 => 1957, 55 => 2074,
                60 => 2205, 65 => 2354, 70 => 2525, 75 => 2723, 80 => 3004],
        ],
    ];

    /** Expoente clássico de Riegel para prever tempo em outra distância. */
    private const EXPOENTE_RIEGEL = 1.06;

    /**
     * Em que percentil da faixa etária este VO2max cai.
     *
     * @return array{percentil: int, classificacao: string, mediana: float}|null
     */
    public static function percentilVo2max(?float $vo2max, ?string $sexo, ?int $idade): ?array
    {
        $coluna = self::colunaVo2max($sexo, $idade);

        if ($vo2max === null || $vo2max <= 0 || $coluna === null) {
            return null;
        }

        $percentis = array_keys($coluna);
        $primeiro = $percentis[0];
        $ultimo = $percentis[count($percentis) - 1];

        if ($vo2max <= $coluna[$primeiro]) {
            $percentil = $primeiro;
        } elseif ($vo2max >= $coluna[$ultimo]) {
            $percentil = $ultimo;
        } else {
            $percentil = $ultimo;

            for ($i = 0; $i < count($percentis) - 1; $i++) {
                $baixo = $percentis[$i];
                $alto = $percentis[$i + 1];

                if ($vo2max >= $coluna[$baixo] && $vo2max <= $coluna[$alto]) {
                    $percentil = (int) round(self::interpolar(
                        $vo2max,
                        $coluna[$baixo], $coluna[$alto],
                        $baixo, $alto
                    ));
                    break;
                }
            }
        }

        return [
            'percentil' => $percentil,
            'classificacao' => self::classificar($percentil),
            'mediana' => $coluna[50],
        ];
    }

    /** A faixa etária inteira, para desenhar a régua de percentis na tela. */
    public static function faixaVo2max(?string $sexo, ?int $idade): ?array
    {
        return self::colunaVo2max($sexo, $idade);
    }

    /**
     * Idade cuja mediana de VO2max bate com a sua — a "idade fitness".
     * Acima do topo da tabela devolve a idade mais nova disponível.
     */
    public static function idadeFitness(?float $vo2max, ?string $sexo): ?int
    {
        $tabela = self::VO2MAX[self::sexo($sexo)] ?? null;

        if ($vo2max === null || $vo2max <= 0 || $tabela === null) {
            return null;
        }

        $faixas = array_keys($tabela);

        // Da mais nova para a mais velha: a primeira faixa cuja mediana o
        // usuário ainda supera é onde ele se encaixa.
        foreach ($faixas as $i => $inicio) {
            $mediana = $tabela[$inicio][50];

            if ($vo2max >= $mediana) {
                if ($i === 0) {
                    return $inicio + 5;
                }

                $anterior = $faixas[$i - 1];

                // Interpola entre os pontos médios das duas faixas.
                return (int) round(self::interpolar(
                    $vo2max,
                    $mediana, $tabela[$anterior][50],
                    $inicio + 5, $anterior + 5
                ));
            }
        }

        return end($faixas) + 5;
    }

    /**
     * Percentual age-graded: quanto o desempenho vale contra o padrão mundial
     * de quem tem a mesma idade e sexo. 100% = recorde mundial da faixa.
     *
     * Distância torta é normalizada por Riegel até 5 ou 10 km, o que também é
     * o que as calculadoras oficiais fazem fora das distâncias de referência.
     *
     * @return array{percentual: float, distancia_referencia: int, tempo_normalizado: int, tempo_padrao: int}|null
     */
    public static function ageGrade(?float $km, ?int $segundos, ?string $sexo, ?int $idade): ?array
    {
        $sexo = self::sexo($sexo);

        if (! $km || ! $segundos || $km < 1.5 || $idade === null || ! isset(self::PADRAO_ESTRADA[$sexo])) {
            return null;
        }

        // Abaixo de 7,5 km o 5 km é a referência mais honesta; acima, o 10 km.
        $referencia = $km < 7.5 ? 5 : 10;
        $normalizado = self::riegel($segundos, $km, (float) $referencia);
        $padrao = self::padraoEstrada($sexo, $referencia, $idade);

        if ($padrao === null || $normalizado <= 0) {
            return null;
        }

        return [
            'percentual' => round($padrao / $normalizado * 100, 1),
            'distancia_referencia' => $referencia,
            'tempo_normalizado' => $normalizado,
            'tempo_padrao' => $padrao,
        ];
    }

    /** Fórmula de Riegel: prevê o tempo em outra distância. */
    public static function riegel(int $segundos, float $kmOrigem, float $kmAlvo): int
    {
        if ($kmOrigem <= 0 || $kmAlvo <= 0) {
            return $segundos;
        }

        return (int) round($segundos * ($kmAlvo / $kmOrigem) ** self::EXPOENTE_RIEGEL);
    }

    /**
     * VO2max estimado a partir de um desempenho (VDOT de Daniels/Gilbert).
     * Serve de plano B quando o Garmin ainda não tem VO2max — e para medir o
     * efeito de mudanças de peso, em `projecaoPorPeso()`.
     */
    public static function vo2maxEstimado(?float $km, ?int $segundos): ?float
    {
        if (! $km || ! $segundos || $km <= 0 || $segundos <= 0) {
            return null;
        }

        $minutos = $segundos / 60;
        $velocidade = ($km * 1000) / $minutos; // metros por minuto

        $vo2 = -4.60 + 0.182258 * $velocidade + 0.000104 * $velocidade ** 2;

        // Fração do VO2max sustentável naquela duração.
        $fracao = 0.8
            + 0.1894393 * exp(-0.012778 * $minutos)
            + 0.2989558 * exp(-0.1932605 * $minutos);

        if ($fracao <= 0) {
            return null;
        }

        return round($vo2 / $fracao, 1);
    }

    /**
     * O caminho inverso: que tempo nesta distância corresponde a este VDOT.
     * Busca binária sobre `vo2maxEstimado()` — não há forma fechada, e assim a
     * ida e a volta usam exatamente o mesmo modelo.
     */
    public static function tempoParaVo2max(float $vo2max, float $km): ?int
    {
        if ($vo2max <= 0 || $km <= 0) {
            return null;
        }

        $rapido = 60;             // 1 min — mais rápido que qualquer humano
        $lento = 60 * 60 * 6;     // 6 h — mais lento que qualquer caminhada

        for ($i = 0; $i < 60; $i++) {
            $meio = (int) (($rapido + $lento) / 2);
            $estimado = self::vo2maxEstimado($km, $meio);

            if ($estimado === null) {
                return null;
            }

            // Mais tempo => VDOT menor.
            if ($estimado > $vo2max) {
                $rapido = $meio;
            } else {
                $lento = $meio;
            }
        }

        return (int) (($rapido + $lento) / 2);
    }

    /**
     * O que o peso muda no desempenho.
     *
     * VO2max relativo é ml/kg/min: perder massa levanta o número mesmo sem
     * nenhum ganho de condicionamento. Aqui a conta é só essa proporção,
     * convertida de volta em pace — é uma estimativa de teto, não promessa.
     *
     * @return array{peso_alvo_kg: float, vo2max_projetado: float, pace_atual_seg_km: int, pace_projetado_seg_km: int, ganho_seg_km: int}|null
     */
    public static function projecaoPorPeso(?float $vo2max, ?float $pesoKg, ?float $pesoAlvoKg, float $km = 5.0): ?array
    {
        if (! $vo2max || ! $pesoKg || ! $pesoAlvoKg || $pesoKg <= 0 || $pesoAlvoKg <= 0) {
            return null;
        }

        if (abs($pesoKg - $pesoAlvoKg) < 0.5) {
            return null;
        }

        $projetado = round($vo2max * ($pesoKg / $pesoAlvoKg), 1);

        $atual = self::tempoParaVo2max($vo2max, $km);
        $futuro = self::tempoParaVo2max($projetado, $km);

        if ($atual === null || $futuro === null) {
            return null;
        }

        $paceAtual = (int) round($atual / $km);
        $paceFuturo = (int) round($futuro / $km);

        return [
            'peso_alvo_kg' => $pesoAlvoKg,
            'vo2max_projetado' => $projetado,
            'pace_atual_seg_km' => $paceAtual,
            'pace_projetado_seg_km' => $paceFuturo,
            'ganho_seg_km' => $paceAtual - $paceFuturo,
        ];
    }

    /**
     * FC máxima estimada por Tanaka (2001): 208 − 0,7 × idade.
     * Mais fiel que o velho 220 − idade, que subestima acima dos 40.
     * Só entra quando o usuário não informou a FC máxima medida.
     */
    public static function fcMaximaEstimada(?int $idade): ?int
    {
        if ($idade === null || $idade < 10 || $idade > 100) {
            return null;
        }

        return (int) round(208 - 0.7 * $idade);
    }

    /** Tempo-padrão da idade, interpolado entre as âncoras de 5 em 5 anos. */
    private static function padraoEstrada(string $sexo, int $distanciaKm, int $idade): ?int
    {
        $tabela = self::PADRAO_ESTRADA[$sexo][$distanciaKm] ?? null;

        if ($tabela === null) {
            return null;
        }

        $idades = array_keys($tabela);
        $maisNova = $idades[0];
        $maisVelha = $idades[count($idades) - 1];

        // Abaixo de 30 não há penalidade de idade; acima de 80 a tabela para.
        if ($idade <= $maisNova) {
            return $tabela[$maisNova];
        }

        if ($idade >= $maisVelha) {
            return $tabela[$maisVelha];
        }

        for ($i = 0; $i < count($idades) - 1; $i++) {
            $baixo = $idades[$i];
            $alto = $idades[$i + 1];

            if ($idade >= $baixo && $idade <= $alto) {
                return (int) round(self::interpolar(
                    $idade,
                    $baixo, $alto,
                    $tabela[$baixo], $tabela[$alto]
                ));
            }
        }

        return null;
    }

    private static function colunaVo2max(?string $sexo, ?int $idade): ?array
    {
        $tabela = self::VO2MAX[self::sexo($sexo)] ?? null;

        if ($tabela === null || $idade === null) {
            return null;
        }

        // Faixas de 10 anos; quem está fora dos limites usa a ponta mais próxima.
        $faixa = (int) (floor($idade / 10) * 10);
        $faixa = max(20, min(70, $faixa));

        return $tabela[$faixa] ?? null;
    }

    /** Sem sexo informado o masculino é o default do painel (usuário único). */
    private static function sexo(?string $sexo): string
    {
        return strtoupper((string) $sexo) === 'F' ? 'F' : 'M';
    }

    private static function interpolar(float $x, float $x1, float $x2, float $y1, float $y2): float
    {
        if ($x2 == $x1) {
            return $y1;
        }

        return $y1 + ($x - $x1) / ($x2 - $x1) * ($y2 - $y1);
    }

    private static function classificar(int $percentil): string
    {
        return match (true) {
            $percentil >= 95 => 'Superior',
            $percentil >= 80 => 'Excelente',
            $percentil >= 60 => 'Acima da média',
            $percentil >= 40 => 'Na média',
            $percentil >= 20 => 'Abaixo da média',
            default => 'Muito abaixo da média',
        };
    }
}
