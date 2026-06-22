<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consorcios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('administradora')->nullable();
            $table->string('tipo'); // contemplado | novo
            $table->string('grupo')->nullable();
            $table->string('cota')->nullable();
            $table->decimal('valor_credito', 15, 2)->nullable();   // valor da carta de crédito
            $table->decimal('valor_mensalidade', 15, 2)->nullable(); // parcela mensal padrão
            $table->unsignedSmallInteger('prazo_meses')->nullable();  // total de parcelas
            $table->unsignedSmallInteger('parcelas_pagas')->nullable(); // ponto de partida informado
            $table->unsignedTinyInteger('dia_vencimento')->nullable(); // 1-31
            $table->date('data_contemplacao')->nullable();
            $table->string('status')->default('ativo'); // ativo | quitado | cancelado
            $table->string('proposta_path')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consorcios');
    }
};
