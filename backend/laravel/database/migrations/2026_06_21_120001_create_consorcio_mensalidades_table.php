<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consorcio_mensalidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consorcio_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('numero')->nullable(); // número da parcela
            $table->string('competencia', 7)->nullable();        // YYYY-MM
            $table->date('vencimento');
            $table->decimal('valor', 15, 2);
            $table->boolean('pago')->default(false);
            $table->date('pago_em')->nullable();
            $table->timestamps();

            $table->index(['consorcio_id', 'vencimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consorcio_mensalidades');
    }
};
