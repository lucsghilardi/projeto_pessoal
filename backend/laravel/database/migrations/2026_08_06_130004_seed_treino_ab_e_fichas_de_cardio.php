<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Preenche as fichas A/B (criadas vazias em 2026_08_05_120008) e cria as
     * duas fichas de cardio isolado.
     *
     * Metodologia: um pouco de cada grupo por dia (perna, ombro, bíceps,
     * tríceps) + 1 puxada e 1 empurrada para não deixar costas/peito de fora —
     * ombro sem trabalho de costas vira dor de ombro. Cada treino fecha em
     * ~40 min, com o cardio final como variável de ajuste nos dias corridos.
     *
     * Zonas de FC (FC máx operacional 181 bpm, Tanaka para 38 anos):
     * Z1 109–127 · Z2 127–145 · Z3 145–154 · Z4 155–168 · Z5 168+.
     */
    public function up(): void
    {
        $now = now();

        $fichas = [
            'Treino A' => [
                'tipo' => 'musculacao',
                'posicao' => 1,
                'exercicios' => [
                    ['nome' => 'Aquecimento — esteira ou bike', 'duracao_min' => 5, 'intensidade' => 'Z1 → Z2', 'observacoes' => 'Subir a intensidade progressivamente até entrar na Z2 (127–145 bpm).'],
                    ['nome' => 'Leg press 45°', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Descanso 75s.'],
                    ['nome' => 'Cadeira extensora', 'series' => 3, 'repeticoes' => '15', 'observacoes' => 'Descanso 45s.'],
                    ['nome' => 'Puxada frontal (pulldown)', 'series' => 3, 'repeticoes' => '10', 'observacoes' => 'Descanso 60s.'],
                    ['nome' => 'Desenvolvimento com halteres', 'series' => 3, 'repeticoes' => '10', 'observacoes' => 'Sentado, com encosto. Descanso 60s.'],
                    ['nome' => 'Rosca direta', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Bi-set com o tríceps corda — emenda sem descanso.'],
                    ['nome' => 'Tríceps corda', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Bi-set com a rosca direta. Descanso 60s ao fim de cada rodada.'],
                    ['nome' => 'Cardio Z2', 'duracao_min' => 12, 'intensidade' => 'Z2 (127–145 bpm)', 'observacoes' => 'Esteira, bike ou elíptico. Dia corrido: corta para 6 min — nunca corte a musculação.'],
                ],
            ],
            'Treino B' => [
                'tipo' => 'musculacao',
                'posicao' => 2,
                'exercicios' => [
                    ['nome' => 'Aquecimento — esteira ou bike', 'duracao_min' => 5, 'intensidade' => 'Z1 → Z2', 'observacoes' => 'Subir a intensidade progressivamente até entrar na Z2 (127–145 bpm).'],
                    ['nome' => 'Agachamento no Smith', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Descanso 75s. Alternativa: hack machine.'],
                    ['nome' => 'Cadeira flexora', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Descanso 60s.'],
                    ['nome' => 'Supino reto com halteres', 'series' => 3, 'repeticoes' => '10', 'observacoes' => 'Descanso 60s.'],
                    ['nome' => 'Elevação lateral', 'series' => 3, 'repeticoes' => '15', 'observacoes' => 'Bi-set com o crucifixo inverso — emenda sem descanso.'],
                    ['nome' => 'Crucifixo inverso', 'series' => 3, 'repeticoes' => '15', 'observacoes' => 'Bi-set com a elevação lateral. Descanso 45s ao fim de cada rodada.'],
                    ['nome' => 'Rosca martelo', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Bi-set com o tríceps testa — emenda sem descanso.'],
                    ['nome' => 'Tríceps testa', 'series' => 3, 'repeticoes' => '12', 'observacoes' => 'Bi-set com a rosca martelo. Descanso 60s ao fim de cada rodada.'],
                    ['nome' => 'Panturrilha em pé (opcional)', 'series' => 3, 'repeticoes' => '20', 'observacoes' => 'Só se sobrar tempo. Descanso 30s.'],
                    ['nome' => 'Cardio Z2', 'duracao_min' => 12, 'intensidade' => 'Z2 (127–145 bpm)', 'observacoes' => 'Esteira, bike ou elíptico. Dia corrido: corta para 6 min — nunca corte a musculação.'],
                ],
            ],
            'Cardio Intervalado' => [
                'tipo' => 'cardio',
                'posicao' => 3,
                'exercicios' => [
                    ['nome' => 'Aquecimento', 'duracao_min' => 8, 'intensidade' => 'Z2 (127–145 bpm)', 'observacoes' => 'Progressivo, terminando dentro da Z2.'],
                    ['nome' => 'Tiros — 1 min forte / 2 min caminhada', 'series' => 6, 'duracao_min' => 18, 'intensidade' => 'Z4 (155–168 bpm) / Z1', 'observacoes' => '6 rodadas. No tiro só sai palavra solta; na caminhada a respiração volta ao normal.'],
                    ['nome' => 'Desaquecimento', 'duracao_min' => 4, 'intensidade' => 'Z1 (até 127 bpm)', 'observacoes' => null],
                ],
            ],
            'Cardio Longo Z2' => [
                'tipo' => 'cardio',
                'posicao' => 4,
                'exercicios' => [
                    ['nome' => 'Base contínua', 'duracao_min' => 40, 'intensidade' => 'Z2 (127–145 bpm)', 'observacoes' => 'Tem que dar pra falar frases inteiras. Passou de 145 bpm, caminha até a FC voltar.'],
                    ['nome' => 'Desaquecimento', 'duracao_min' => 5, 'intensidade' => 'Z1 (até 127 bpm)', 'observacoes' => null],
                ],
            ],
        ];

        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach ($fichas as $nome => $ficha) {
                DB::table('saude_treinos')->insertOrIgnore([
                    'user_id' => $userId,
                    'nome' => $nome,
                    'tipo' => $ficha['tipo'],
                    'posicao' => $ficha['posicao'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $treinoId = DB::table('saude_treinos')
                    ->where('user_id', $userId)
                    ->where('nome', $nome)
                    ->value('id');

                // Não sobrescreve ficha que o usuário já montou.
                if (DB::table('saude_exercicios')->where('treino_id', $treinoId)->exists()) {
                    continue;
                }

                foreach ($ficha['exercicios'] as $posicao => $exercicio) {
                    DB::table('saude_exercicios')->insert($exercicio + [
                        'user_id' => $userId,
                        'treino_id' => $treinoId,
                        'series' => null,
                        'repeticoes' => null,
                        'carga' => null,
                        'duracao_min' => null,
                        'intensidade' => null,
                        'observacoes' => null,
                        'posicao' => $posicao,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        $this->corrigirPerfil($now);
    }

    /**
     * O perfil em saude_metas tinha altura e nascimento de teste, e não havia
     * nenhuma pesagem — sem peso, SaudeNutricaoService::perfil() nunca fica
     * `completo` e a tela de calorias devolve null em tudo.
     */
    private function corrigirPerfil(mixed $now): void
    {
        $adminEmail = Str::lower(trim((string) env('ADMIN_EMAIL', '')));

        if ($adminEmail === '') {
            return;
        }

        $userId = DB::table('users')->whereRaw('lower(email) = ?', [$adminEmail])->value('id');

        if ($userId === null) {
            return;
        }

        DB::table('saude_metas')
            ->where('user_id', $userId)
            ->update([
                'altura_cm' => 172,
                'data_nascimento' => '1987-12-28',
                'updated_at' => $now,
            ]);

        if (! DB::table('saude_pesos')->where('user_id', $userId)->exists()) {
            DB::table('saude_pesos')->insertOrIgnore([
                'user_id' => $userId,
                'data' => now((string) config('saude.timezone'))->toDateString(),
                'peso_kg' => 94,
                'observacao' => 'Peso inicial do plano de treino.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Não remove dados para evitar perda de cadastros do usuário.
    }
};
