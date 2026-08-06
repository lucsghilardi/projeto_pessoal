<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_exercicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treino_id')->constrained('saude_treinos')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->unsignedSmallInteger('series')->nullable();
            $table->string('repeticoes', 30)->nullable(); // livre: "12", "8-12"
            $table->string('carga', 30)->nullable(); // livre: "24 kg", "placa 5"
            $table->string('observacoes', 255)->nullable();
            $table->unsignedSmallInteger('posicao')->default(0);
            $table->timestamps();

            $table->index(['treino_id', 'posicao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_exercicios');
    }
};
