<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_suplementos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('marca', 120)->nullable();
            $table->string('dose', 60)->nullable(); // ex.: "1 cápsula"
            $table->time('horario'); // horário alvo no fuso config('saude.timezone')
            $table->string('instrucao', 160)->nullable(); // ex.: "em jejum", "junto com o almoço"
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->unsignedSmallInteger('posicao')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_suplementos');
    }
};
