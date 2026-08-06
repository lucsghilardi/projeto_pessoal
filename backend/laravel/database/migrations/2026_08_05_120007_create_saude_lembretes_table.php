<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_lembretes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suplemento_id')->constrained('saude_suplementos')->cascadeOnDelete();
            $table->date('data');
            $table->timestamp('enviado_em');
            $table->timestamps();

            // No máximo um lembrete por suplemento por dia.
            $table->unique(['suplemento_id', 'data']);
            $table->index(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_lembretes');
    }
};
