<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consorcios', function (Blueprint $table) {
            // Total já pago fora do carnê (histórico informado), p.ex. de cotas já contempladas
            // cujas parcelas antigas variaram. Soma-se ao que for marcado pago no carnê.
            $table->decimal('valor_pago_inicial', 15, 2)->nullable()->after('parcelas_pagas');
        });
    }

    public function down(): void
    {
        Schema::table('consorcios', function (Blueprint $table) {
            $table->dropColumn('valor_pago_inicial');
        });
    }
};
