<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_cardio_sessoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Ficha que prescreveu (cardio ao fim do A/B, ou ficha de cardio). Null = avulso.
            $table->foreignId('treino_id')->nullable()->constrained('saude_treinos')->nullOnDelete();
            $table->date('data');
            $table->time('horario')->nullable(); // startTimeLocal quando vem do Garmin
            $table->string('nome', 120)->nullable();
            $table->string('modalidade', 20); // corrida_rua|esteira|bike|eliptico|caminhada|outro
            $table->unsignedSmallInteger('duracao_min');
            $table->decimal('distancia_km', 6, 2)->nullable();
            $table->unsignedSmallInteger('calorias')->nullable();
            $table->unsignedSmallInteger('fc_media')->nullable();
            $table->unsignedSmallInteger('fc_maxima')->nullable();
            $table->string('intensidade', 30)->nullable(); // livre: "Z2", "6,5 km/h"
            $table->string('origem', 20)->default('manual'); // manual|garmin
            // Os ids do Garmin já passam de 10 bilhões — não cabe em integer.
            $table->unsignedBigInteger('garmin_activity_id')->nullable()->unique();
            $table->string('observacao', 255)->nullable();
            $table->timestamps();

            // Sem unique em (user_id, data): mais de um cardio por dia é o caso de uso.
            $table->index(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_cardio_sessoes');
    }
};
