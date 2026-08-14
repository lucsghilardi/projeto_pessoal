<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Análise da corrida gerada pela IA.
     *
     * Uma por sessão (unique), como em `whatsapp_relatorios`: saída cara de IA
     * mora em tabela de domínio, não em cache. Reabrir a corrida não gasta
     * token; refazer a análise sobrescreve via updateOrCreate.
     */
    public function up(): void
    {
        Schema::create('saude_cardio_analises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cardio_sessao_id')->unique()
                ->constrained('saude_cardio_sessoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('dados');
            $table->string('modelo', 60);
            $table->timestamp('gerado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_cardio_analises');
    }
};
