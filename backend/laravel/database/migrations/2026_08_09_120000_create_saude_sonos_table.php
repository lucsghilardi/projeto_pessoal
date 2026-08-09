<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma noite por dia. `data` é o dia em que se ACORDOU — é a convenção do
     * Garmin (quem dorme 23h do dia 7 e acorda 6h do dia 8 é registro do dia 8)
     * e também é o que o painel quer mostrar em "como dormi hoje".
     */
    public function up(): void
    {
        Schema::create('saude_sonos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('data');
            // Sono efetivo, já sem os despertares (max. 65535 min sobra muito).
            $table->unsignedSmallInteger('duracao_min');
            $table->unsignedSmallInteger('profundo_min')->nullable();
            $table->unsignedSmallInteger('leve_min')->nullable();
            $table->unsignedSmallInteger('rem_min')->nullable();
            $table->unsignedSmallInteger('acordado_min')->nullable();
            $table->unsignedSmallInteger('cochilo_min')->nullable();
            $table->unsignedTinyInteger('despertares')->nullable();
            $table->unsignedTinyInteger('score')->nullable(); // 0-100, do próprio Garmin
            $table->string('score_qualificador', 20)->nullable(); // POOR|FAIR|GOOD|EXCELLENT
            $table->time('inicio')->nullable();
            $table->time('fim')->nullable();
            $table->decimal('hrv_medio', 5, 1)->nullable();
            $table->decimal('estresse_medio', 5, 1)->nullable();
            $table->string('origem', 20)->default('manual'); // manual|garmin
            $table->string('observacao', 255)->nullable();
            $table->timestamps();

            // Diferente do cardio: duas noites no mesmo dia não existem, e é o
            // unique que torna a reimportação do Garmin idempotente.
            $table->unique(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_sonos');
    }
};
