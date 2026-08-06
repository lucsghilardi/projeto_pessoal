<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cria os suplementos (stack atual) e os treinos A/B para os usuarios
     * existentes. Horarios e instrucoes sao sugestoes editaveis na UI.
     */
    public function up(): void
    {
        $now = now();

        $suplementos = [
            [
                'nome' => 'FIT S36 Advanced Formula',
                'marca' => 'AlwaysFit',
                'horario' => '07:00',
                'instrucao' => 'Em jejum / pré-treino',
                'observacoes' => 'Contém cafeína — não tomar junto com o DTX Black; evitar após as 16h.',
                'posicao' => 1,
            ],
            [
                'nome' => 'Astragalus 450mg',
                'marca' => 'Self Esteem',
                'horario' => '10:00',
                'instrucao' => 'Com o café da manhã',
                'observacoes' => 'Pode interagir com anticoagulantes/imunossupressores — confirme com quem te acompanha.',
                'posicao' => 2,
            ],
            [
                'nome' => 'Espinheira Santa',
                'marca' => 'Avantage Lab',
                'horario' => '11:45',
                'instrucao' => '30 min antes do almoço',
                'observacoes' => 'Proteção gástrica (azia/gastrite).',
                'posicao' => 3,
            ],
            [
                'nome' => 'Óleo de Semente de Abóbora + D3 + E',
                'marca' => 'AlwaysFit',
                'horario' => '12:20',
                'instrucao' => 'Junto com o almoço (a gordura melhora a absorção da D3/E)',
                'observacoes' => null,
                'posicao' => 4,
            ],
            [
                'nome' => 'DTX Black',
                'marca' => 'New Skin',
                'horario' => '15:00',
                'instrucao' => 'Início da tarde, com bastante água',
                'observacoes' => 'Contém cafeína — não tomar junto com o FIT S36; evitar após as 16h.',
                'posicao' => 5,
            ],
        ];

        foreach (DB::table('users')->pluck('id') as $userId) {
            // Não duplica se o usuário já cadastrou suplementos.
            if (! DB::table('saude_suplementos')->where('user_id', $userId)->exists()) {
                foreach ($suplementos as $suplemento) {
                    DB::table('saude_suplementos')->insert($suplemento + [
                        'user_id' => $userId,
                        'ativo' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach (['Treino A' => 1, 'Treino B' => 2] as $nome => $posicao) {
                DB::table('saude_treinos')->insertOrIgnore([
                    'user_id' => $userId,
                    'nome' => $nome,
                    'posicao' => $posicao,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nao remove dados para evitar perda de cadastros do usuario.
    }
};
