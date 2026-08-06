<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_treino_sessoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treino_id')->constrained('saude_treinos')->cascadeOnDelete();
            $table->date('data');
            $table->timestamps();

            // Um treino por dia; trocar A <-> B atualiza a mesma linha.
            $table->unique(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_treino_sessoes');
    }
};
