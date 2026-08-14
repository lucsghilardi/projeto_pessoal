<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os três números que faltavam para analisar cardio por zona.
     *
     * `fc_maxima` é do usuário (o relógio não mede FC máxima real, só o maior
     * valor já visto) e fica editável no painel; quando null, o cálculo cai no
     * Tanaka. `vo2max` e `fc_limiar` são espelho do que o Garmin calcula e o
     * sync sobrescreve a cada rodada.
     */
    public function up(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->unsignedSmallInteger('fc_maxima')->nullable()->after('nivel_atividade');
            $table->decimal('vo2max', 4, 1)->nullable()->after('fc_maxima');
            $table->unsignedSmallInteger('fc_limiar')->nullable()->after('vo2max');
        });
    }

    public function down(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->dropColumn(['fc_maxima', 'vo2max', 'fc_limiar']);
        });
    }
};
