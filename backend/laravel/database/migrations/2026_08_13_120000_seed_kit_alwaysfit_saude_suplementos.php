<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Acrescenta os itens novos do kit AlwaysFit (NAC e PRO3 Magnesio) ao stack
     * de suplementos. O FIT S36 do mesmo kit ja estava cadastrado e nao e tocado.
     * Horarios e instrucoes sao sugestoes editaveis na UI.
     */
    public function up(): void
    {
        $now = now();

        $suplementos = [
            [
                'nome' => 'NAC N-Acetil-L-Cisteína 600mg',
                'marca' => 'AlwaysFit',
                'dose' => '1 cápsula (750mg)',
                'horario' => '12:30',
                'instrucao' => 'Logo após o almoço',
                'observacoes' => 'Após a refeição para evitar desconforto gástrico. Se usar qualquer medicação contínua, confirme com quem te acompanha.',
            ],
            [
                'nome' => 'PRO3 Magnésio',
                'marca' => 'AlwaysFit',
                'dose' => '2 cápsulas (500mg)',
                'horario' => '21:30',
                'instrucao' => 'À noite, após o jantar',
                'observacoes' => 'L-treonina + dimalato + quelato — a treonina é a forma ligada a sono/cognição; acompanhe o sono no Garmin nas próximas semanas. Dose alta de magnésio pode competir na absorção de outros minerais: manter afastado ~2h dos demais suplementos.',
            ],
        ];

        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach ($suplementos as $suplemento) {
                // Nao duplica se o usuario ja cadastrou o mesmo item.
                $jaExiste = DB::table('saude_suplementos')
                    ->where('user_id', $userId)
                    ->where('nome', $suplemento['nome'])
                    ->exists();

                if ($jaExiste) {
                    continue;
                }

                $posicao = (int) DB::table('saude_suplementos')
                    ->where('user_id', $userId)
                    ->max('posicao') + 1;

                DB::table('saude_suplementos')->insert($suplemento + [
                    'user_id' => $userId,
                    'ativo' => true,
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
