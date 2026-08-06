<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resumo diário do relógio. É a fonte preferencial de gasto para o TDEE
     * dinâmico: `calorias_ativas` cobre o dia inteiro (inclusive os passos fora
     * de atividades registradas), coisa que somar sessão a sessão não pega.
     */
    public function up(): void
    {
        Schema::create('saude_dias_garmin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('data');
            $table->unsignedInteger('passos')->nullable();
            $table->unsignedSmallInteger('calorias_ativas')->nullable();
            $table->unsignedSmallInteger('calorias_totais')->nullable();
            $table->unsignedSmallInteger('fc_repouso')->nullable();
            $table->unsignedSmallInteger('minutos_intensidade')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_dias_garmin');
    }
};
