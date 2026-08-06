<?php

namespace App\Services\Saude;

use App\Models\SaudeMeta;
use App\Models\SaudePeso;
use App\Models\SaudeRefeicao;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Cálculos nutricionais do módulo Saúde: TMB (Mifflin-St Jeor), TDEE, metas de
 * calorias/proteína com travas de segurança, resumo do dia e projeção de peso.
 * Compartilhado pelo painel, pela resposta no WhatsApp e pelo briefing matinal.
 */
class SaudeNutricaoService
{
    public const FATORES_ATIVIDADE = [
        'sedentario' => 1.2,
        'leve' => 1.375,
        'moderado' => 1.55,
        'intenso' => 1.725,
        'atleta' => 1.9,
    ];

    private const KCAL_POR_KG = 7700;

    /**
     * Fator usado no TDEE dinâmico: só a vida cotidiana, sem treino nenhum —
     * o exercício entra pelo gasto medido, não pelo multiplicador.
     */
    private const FATOR_BASE_PADRAO = 1.2;

    /** Janela da média de gasto usada pela projeção (não pode oscilar com o dia). */
    private const DIAS_MEDIA_GASTO = 28;

    public function __construct(private readonly SaudeCardioService $cardio) {}

    /**
     * Perfil consolidado: meta + última pesagem. `completo` indica se dá para
     * calcular TMB/TDEE (sexo, nascimento, altura e ao menos uma pesagem).
     *
     * @return array{meta: SaudeMeta|null, peso_atual: float|null, data_ultimo_peso: string|null, idade: int|null, completo: bool}
     */
    public function perfil(User $user): array
    {
        $meta = SaudeMeta::where('user_id', $user->id)->first();
        $ultimoPeso = SaudePeso::where('user_id', $user->id)->orderByDesc('data')->first();

        $pesoAtual = $ultimoPeso !== null ? (float) $ultimoPeso->peso_kg : null;

        $idade = null;
        if ($meta?->data_nascimento !== null) {
            $idade = Carbon::parse($meta->data_nascimento->toDateString(), config('saude.timezone'))->age;
        }

        $completo = $meta !== null
            && in_array($meta->sexo, ['M', 'F'], true)
            && $idade !== null
            && $meta->altura_cm !== null
            && $pesoAtual !== null;

        return [
            'meta' => $meta,
            'peso_atual' => $pesoAtual,
            'data_ultimo_peso' => $ultimoPeso?->data?->toDateString(),
            'idade' => $idade,
            'completo' => $completo,
        ];
    }

    /**
     * TMB por Mifflin-St Jeor.
     */
    public function tmb(string $sexo, int $idade, int $alturaCm, float $pesoKg): int
    {
        $base = 10 * $pesoKg + 6.25 * $alturaCm - 5 * $idade;

        return (int) round($base + ($sexo === 'M' ? 5 : -161));
    }

    public function tdee(int $tmb, ?string $nivelAtividade): int
    {
        $fator = self::FATORES_ATIVIDADE[$nivelAtividade] ?? self::FATORES_ATIVIDADE['sedentario'];

        return (int) round($tmb * $fator);
    }

    /**
     * Metas do dia a partir de um perfil já montado por perfil().
     * Overrides manuais (calorias_alvo / proteinas_alvo_g) têm prioridade.
     *
     * Com `gasto_dinamico` ligado, o TDEE deixa de sair do fator de atividade
     * (que já embute o treino) e passa a ser TMB × fator_base + o gasto real
     * do dia — daí a meta subir conforme o dia rende.
     *
     * @param  array{meta: SaudeMeta|null, peso_atual: float|null, idade: int|null, completo: bool}  $perfil
     * @return array{completo: bool, tmb: int|null, tdee: int|null, calorias: int|null, proteinas_g: int|null, deficit: int|null, gasto_exercicio: int, gasto_dinamico: bool}
     */
    public function metas(array $perfil, int $gastoExercicio = 0): array
    {
        /** @var SaudeMeta|null $meta */
        $meta = $perfil['meta'];

        $tmb = $tdee = $calorias = $deficit = null;
        $dinamico = (bool) ($meta?->gasto_dinamico ?? false);

        if ($perfil['completo']) {
            $tmb = $this->tmb($meta->sexo, (int) $perfil['idade'], (int) $meta->altura_cm, (float) $perfil['peso_atual']);
            $tdee = $dinamico
                ? (int) round($tmb * (float) ($meta->fator_base ?? self::FATOR_BASE_PADRAO)) + $gastoExercicio
                : $this->tdee($tmb, $meta->nivel_atividade);
            $calorias = $meta->calorias_alvo ?? $this->caloriasAlvo($tdee, (float) $perfil['peso_atual'], $meta);
            $deficit = $tdee - $calorias;
        } elseif ($meta?->calorias_alvo !== null) {
            // Sem perfil completo, mas com meta manual: dá para acompanhar o dia.
            $calorias = $meta->calorias_alvo;
        }

        $proteinas = $meta?->proteinas_alvo_g;
        if ($proteinas === null && $perfil['peso_atual'] !== null) {
            $proteinas = (int) round($perfil['peso_atual'] * (float) config('saude.nutricao.proteina_g_por_kg'));
        }

        return [
            'completo' => $perfil['completo'],
            'tmb' => $tmb,
            'tdee' => $tdee,
            'calorias' => $calorias,
            'proteinas_g' => $proteinas,
            'deficit' => $deficit,
            'gasto_exercicio' => $dinamico ? $gastoExercicio : 0,
            'gasto_dinamico' => $dinamico,
        ];
    }

    /**
     * Refeições + somas do dia, metas e o que ainda pode ser consumido.
     * `restante` pode ser negativo (estourou a meta) — a exibição decide o tom.
     */
    public function resumoDia(User $user, string $data): array
    {
        $refeicoes = SaudeRefeicao::where('user_id', $user->id)
            ->where('data', $data)
            ->orderBy('horario')
            ->orderBy('id')
            ->get();

        $consumido = [
            'calorias' => (int) $refeicoes->sum('calorias'),
            'proteinas_g' => round($refeicoes->sum(fn (SaudeRefeicao $r) => (float) $r->proteinas_g), 1),
            'carboidratos_g' => round($refeicoes->sum(fn (SaudeRefeicao $r) => (float) ($r->carboidratos_g ?? 0)), 1),
            'gorduras_g' => round($refeicoes->sum(fn (SaudeRefeicao $r) => (float) ($r->gorduras_g ?? 0)), 1),
        ];

        $perfil = $this->perfil($user);
        $metas = $this->metas($perfil, $this->gastoDoDia($user, $data, $perfil));

        $restante = [
            'calorias' => $metas['calorias'] !== null ? $metas['calorias'] - $consumido['calorias'] : null,
            'proteinas_g' => $metas['proteinas_g'] !== null
                ? round($metas['proteinas_g'] - $consumido['proteinas_g'], 1)
                : null,
        ];

        return [
            'data' => $data,
            'perfil_completo' => $perfil['completo'],
            'refeicoes' => $refeicoes,
            'consumido' => $consumido,
            'metas' => $metas,
            'restante' => $restante,
            'gasto_exercicio' => $metas['gasto_exercicio'],
        ];
    }

    /**
     * Projeção de emagrecimento em pontos semanais: linha do plano (déficit
     * planejado) vs linha do ritmo real (tendência das pesagens de ~4 semanas).
     * Null quando não há pesagem ou nenhum ritmo calculável.
     */
    public function projecao(User $user): ?array
    {
        $perfil = $this->perfil($user);
        if ($perfil['peso_atual'] === null) {
            return null;
        }

        // Média, e não o gasto de hoje: com TDEE dinâmico, usar o dia corrente
        // faria a projeção despencar todo dia de descanso.
        $metas = $this->metas($perfil, $this->gastoMedio($user, $perfil));
        /** @var SaudeMeta|null $meta */
        $meta = $perfil['meta'];
        $pesoAtual = (float) $perfil['peso_atual'];
        $pesoMeta = $meta?->peso_meta_kg !== null ? (float) $meta->peso_meta_kg : null;

        $ritmoPlano = null;
        if ($metas['tdee'] !== null && $metas['calorias'] !== null) {
            $ritmoPlano = round(($metas['tdee'] - $metas['calorias']) * 7 / self::KCAL_POR_KG, 2);
        }

        $ritmoReal = $this->ritmoRealSemanal($user);

        if ($ritmoPlano === null && $ritmoReal === null) {
            return null;
        }

        $tz = config('saude.timezone');
        $hoje = now($tz)->startOfDay();

        // Horizonte: até a data alvo ou até alcançar a meta no plano; 4-52 semanas.
        $semanas = 26;
        if ($meta?->data_alvo !== null) {
            $dataAlvo = Carbon::parse($meta->data_alvo->toDateString(), $tz);
            $semanas = (int) ceil($hoje->diffInDays($dataAlvo, false) / 7);
        } elseif ($pesoMeta !== null && $ritmoPlano !== null && $ritmoPlano > 0 && $pesoAtual > $pesoMeta) {
            $semanas = (int) ceil(($pesoAtual - $pesoMeta) / $ritmoPlano);
        }
        $semanas = max(4, min(52, $semanas + 2));

        $pontos = [];
        for ($i = 0; $i <= $semanas; $i++) {
            $plano = $ritmoPlano !== null ? $pesoAtual - $ritmoPlano * $i : null;
            $real = $ritmoReal !== null ? $pesoAtual - $ritmoReal * $i : null;
            if ($plano !== null && $pesoMeta !== null) {
                $plano = max($plano, $pesoMeta);
            }
            if ($real !== null && $pesoMeta !== null && $ritmoReal > 0) {
                $real = max($real, $pesoMeta);
            }

            $pontos[] = [
                'data' => $hoje->copy()->addWeeks($i)->toDateString(),
                'plano' => $plano !== null ? round($plano, 1) : null,
                'ritmo_real' => $real !== null ? round($real, 1) : null,
            ];
        }

        $dataPrevista = null;
        if ($pesoMeta !== null && $ritmoReal !== null && $ritmoReal > 0 && $pesoAtual > $pesoMeta) {
            $semanasAteMeta = ($pesoAtual - $pesoMeta) / $ritmoReal;
            if ($semanasAteMeta <= 104) {
                $dataPrevista = $hoje->copy()->addDays((int) round($semanasAteMeta * 7))->toDateString();
            }
        }

        return [
            'peso_atual' => round($pesoAtual, 1),
            'peso_meta' => $pesoMeta !== null ? round($pesoMeta, 1) : null,
            'data_alvo' => $meta?->data_alvo?->toDateString(),
            'ritmo_plano_kg_semana' => $ritmoPlano,
            'ritmo_real_kg_semana' => $ritmoReal,
            'data_prevista_meta' => $dataPrevista,
            'pontos' => $pontos,
        ];
    }

    /**
     * Gasto de exercício de um dia. Zero quando o TDEE dinâmico está desligado
     * — assim `metas()` segue exatamente o caminho antigo.
     *
     * @param  array{meta: SaudeMeta|null, peso_atual: float|null}  $perfil
     */
    private function gastoDoDia(User $user, string $data, array $perfil): int
    {
        if (! ($perfil['meta']?->gasto_dinamico ?? false)) {
            return 0;
        }

        return $this->cardio->caloriasDoDia($user->id, $data, $perfil['peso_atual']);
    }

    /**
     * Média diária de gasto das últimas semanas, para a projeção.
     *
     * @param  array{meta: SaudeMeta|null, peso_atual: float|null}  $perfil
     */
    private function gastoMedio(User $user, array $perfil): int
    {
        if (! ($perfil['meta']?->gasto_dinamico ?? false)) {
            return 0;
        }

        $tz = (string) config('saude.timezone');
        $hoje = now($tz)->toDateString();
        $de = now($tz)->subDays(self::DIAS_MEDIA_GASTO - 1)->toDateString();

        return $this->cardio->mediaDiariaCalorias($user->id, $de, $hoje, $perfil['peso_atual']);
    }

    /**
     * Déficit planejado com travas: derivado da meta/data alvo quando existem,
     * senão o padrão; nunca acima do teto nem de 25% do TDEE.
     */
    private function deficitPlanejado(int $tdee, float $pesoAtual, SaudeMeta $meta): int
    {
        $pesoMeta = $meta->peso_meta_kg !== null ? (float) $meta->peso_meta_kg : null;

        // Já chegou na meta: manutenção.
        if ($pesoMeta !== null && $pesoAtual <= $pesoMeta) {
            return 0;
        }

        $deficit = (float) config('saude.nutricao.deficit_padrao_kcal');
        if ($pesoMeta !== null && $meta->data_alvo !== null) {
            $tz = config('saude.timezone');
            $dias = now($tz)->startOfDay()->diffInDays(Carbon::parse($meta->data_alvo->toDateString(), $tz), false);
            if ($dias >= 1) {
                $deficit = ($pesoAtual - $pesoMeta) * self::KCAL_POR_KG / $dias;
            }
        }

        $teto = min((float) config('saude.nutricao.deficit_maximo_kcal'), 0.25 * $tdee);

        return (int) round(min($deficit, $teto));
    }

    private function caloriasAlvo(int $tdee, float $pesoAtual, SaudeMeta $meta): int
    {
        $calorias = $tdee - $this->deficitPlanejado($tdee, $pesoAtual, $meta);
        $piso = (int) (config('saude.nutricao.calorias_minimas')[$meta->sexo] ?? 1200);

        return max($calorias, $piso);
    }

    /**
     * Ritmo real de perda (kg/semana, positivo = perdendo) pela primeira e
     * última pesagem dos últimos 28 dias; exige ao menos 7 dias de intervalo.
     */
    private function ritmoRealSemanal(User $user): ?float
    {
        $desde = now(config('saude.timezone'))->subDays(28)->toDateString();

        $pesos = SaudePeso::where('user_id', $user->id)
            ->where('data', '>=', $desde)
            ->orderBy('data')
            ->get();

        if ($pesos->count() < 2) {
            return null;
        }

        $primeiro = $pesos->first();
        $ultimo = $pesos->last();
        $dias = (int) $primeiro->data->diffInDays($ultimo->data);
        if ($dias < 7) {
            return null;
        }

        return round(((float) $primeiro->peso_kg - (float) $ultimo->peso_kg) / $dias * 7, 2);
    }
}
