<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_relatorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 20); // diario|matinal
            $table->date('referencia_data');
            // Estrutura devolvida pela IA (pendências, promessas, sugestões...).
            $table->json('dados');
            // Versão em texto enviada pelo WhatsApp.
            $table->text('texto_whatsapp')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            // Regerar no mesmo dia substitui o relatório (updateOrCreate).
            $table->unique(['user_id', 'tipo', 'referencia_data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_relatorios');
    }
};
