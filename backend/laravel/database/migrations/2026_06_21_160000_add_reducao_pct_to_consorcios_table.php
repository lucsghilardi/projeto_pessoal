<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consorcios', function (Blueprint $table) {
            // % de redução da parcela até a contemplação (ex.: 50). 0/null = parcela cheia.
            // Usado para estimar o total real a pagar (parcela cheia = reduzida / (1 - %)).
            $table->decimal('reducao_pct', 5, 2)->nullable()->after('valor_mensalidade');
        });
    }

    public function down(): void
    {
        Schema::table('consorcios', function (Blueprint $table) {
            $table->dropColumn('reducao_pct');
        });
    }
};
