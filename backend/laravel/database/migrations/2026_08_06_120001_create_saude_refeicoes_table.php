<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_refeicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('data');
            $table->time('horario');
            $table->string('nome', 120);
            $table->string('tipo', 20)->nullable(); // cafe_da_manha|almoco|jantar|lanche|outro
            $table->json('itens')->nullable(); // [{nome, quantidade, calorias, proteinas_g}]
            $table->unsignedSmallInteger('calorias');
            $table->decimal('proteinas_g', 5, 1)->default(0);
            $table->decimal('carboidratos_g', 5, 1)->nullable();
            $table->decimal('gorduras_g', 5, 1)->nullable();
            $table->string('confianca', 10)->nullable(); // alta|media|baixa (estimativa da IA)
            $table->string('origem', 20)->default('manual'); // whatsapp_foto|whatsapp_texto|manual
            $table->foreignId('whatsapp_mensagem_id')->nullable()->constrained('whatsapp_mensagens')->nullOnDelete();
            $table->string('foto_path')->nullable();
            $table->string('observacao', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_refeicoes');
    }
};
