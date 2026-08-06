<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite fichas de cardio ao lado das de musculação. `tipo` é só prescrição
     * e agrupamento na UI — a execução de uma ficha de cardio é gravada em
     * saude_cardio_sessoes (com treino_id), nunca em saude_treino_sessoes, que
     * tem UNIQUE(user_id, data) e sobrescreveria o treino do dia.
     */
    public function up(): void
    {
        Schema::table('saude_treinos', function (Blueprint $table) {
            $table->string('tipo', 20)->default('musculacao')->after('nome'); // musculacao|cardio
        });

        Schema::table('saude_exercicios', function (Blueprint $table) {
            // Blocos de cardio ("8 min aquecimento Z2") e o cardio final do A/B.
            $table->unsignedSmallInteger('duracao_min')->nullable()->after('carga');
            $table->string('intensidade', 30)->nullable()->after('duracao_min'); // livre: "Z2", "6,5 km/h"
        });
    }

    public function down(): void
    {
        Schema::table('saude_treinos', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });

        Schema::table('saude_exercicios', function (Blueprint $table) {
            $table->dropColumn(['duracao_min', 'intensidade']);
        });
    }
};
