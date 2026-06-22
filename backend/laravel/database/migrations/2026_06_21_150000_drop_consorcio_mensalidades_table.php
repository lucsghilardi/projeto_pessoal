<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // As parcelas do carnê passaram a ser contas a pagar (payables) vinculadas ao
        // consórcio. A tabela de mensalidades não é mais usada (dados já migrados).
        Schema::dropIfExists('consorcio_mensalidades');
    }

    public function down(): void
    {
        Schema::create('consorcio_mensalidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consorcio_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('numero')->nullable();
            $table->string('competencia', 7)->nullable();
            $table->date('vencimento');
            $table->decimal('valor', 15, 2);
            $table->boolean('pago')->default(false);
            $table->date('pago_em')->nullable();
            $table->timestamps();

            $table->index(['consorcio_id', 'vencimento']);
        });
    }
};
