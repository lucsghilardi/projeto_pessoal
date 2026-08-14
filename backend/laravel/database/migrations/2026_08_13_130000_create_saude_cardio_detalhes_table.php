<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache local do detalhe de uma atividade do Garmin.
     *
     * Fica em tabela separada de propósito: são três requisições ao Garmin por
     * atividade, então o detalhe só é buscado quando alguém abre a corrida no
     * painel. A ausência de linha aqui é o sinal de "ainda não busquei".
     */
    public function up(): void
    {
        Schema::create('saude_cardio_detalhes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cardio_sessao_id')->unique()
                ->constrained('saude_cardio_sessoes')->cascadeOnDelete();
            $table->unsignedBigInteger('garmin_activity_id');

            // Splits por volta e tempo em cada zona de FC, já curados pelo sidecar.
            $table->json('splits')->nullable();
            $table->json('zonas_fc')->nullable();

            // Duração e distância com a precisão original: a sessão guarda
            // `duracao_min` arredondado, o que estraga o cálculo de pace.
            $table->unsignedInteger('duracao_seg')->nullable();
            $table->unsignedInteger('tempo_movimento_seg')->nullable();
            $table->unsignedInteger('distancia_m')->nullable();
            $table->unsignedInteger('passos')->nullable();

            $table->unsignedSmallInteger('cadencia_media')->nullable();
            $table->unsignedSmallInteger('passada_media_cm')->nullable();
            $table->unsignedSmallInteger('potencia_media')->nullable();
            $table->unsignedSmallInteger('fc_minima')->nullable();

            $table->smallInteger('elevacao_ganho_m')->nullable();
            $table->smallInteger('elevacao_perda_m')->nullable();

            $table->decimal('training_effect_aerobico', 3, 1)->nullable();
            $table->decimal('training_effect_anaerobico', 3, 1)->nullable();
            $table->decimal('vo2max', 4, 1)->nullable();

            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_cardio_detalhes');
    }
};
