<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Previsões de prova calculadas pelo Garmin, em segundos.
     *
     * Ficam aqui porque a alternativa — derivar o tempo de prova do VDOT de uma
     * corrida qualquer — só vale quando a corrida foi em esforço de prova. Num
     * treino leve o VDOT despenca e a projeção viraria ficção. O Garmin
     * reconcilia isso com o histórico inteiro; o sync espelha o resultado.
     */
    public function up(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->unsignedInteger('previsao_5k_seg')->nullable()->after('fc_limiar');
            $table->unsignedInteger('previsao_10k_seg')->nullable()->after('previsao_5k_seg');
            $table->unsignedInteger('previsao_21k_seg')->nullable()->after('previsao_10k_seg');
            $table->unsignedInteger('previsao_42k_seg')->nullable()->after('previsao_21k_seg');
        });
    }

    public function down(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->dropColumn([
                'previsao_5k_seg', 'previsao_10k_seg', 'previsao_21k_seg', 'previsao_42k_seg',
            ]);
        });
    }
};
