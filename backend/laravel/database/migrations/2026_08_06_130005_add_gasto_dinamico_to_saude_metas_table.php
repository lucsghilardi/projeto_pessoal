<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * TDEE dinâmico: em vez de multiplicar a TMB por um fator de atividade que
     * já embute o treino (1.375–1.9), usa TMB × fator_base (só vida cotidiana)
     * + o gasto real do dia. Somar exercício por cima do fator antigo contaria
     * o treino duas vezes.
     *
     * Default `false` de propósito: o caminho antigo continua intacto para
     * quem não ligar a chave.
     */
    public function up(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->boolean('gasto_dinamico')->default(false)->after('nivel_atividade');
            $table->decimal('fator_base', 3, 2)->nullable()->after('gasto_dinamico');
        });

        // Liga para o usuário do painel, que é quem tem o Garmin conectado.
        $adminEmail = Str::lower(trim((string) env('ADMIN_EMAIL', '')));

        if ($adminEmail === '') {
            return;
        }

        $userId = DB::table('users')->whereRaw('lower(email) = ?', [$adminEmail])->value('id');

        if ($userId !== null) {
            DB::table('saude_metas')
                ->where('user_id', $userId)
                ->update(['gasto_dinamico' => true, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->dropColumn(['gasto_dinamico', 'fator_base']);
        });
    }
};
