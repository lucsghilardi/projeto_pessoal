<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            // Vincula uma conta a pagar a um consórcio (parcela do carnê). Ao excluir o
            // consórcio, as parcelas continuam como contas a pagar comuns (nullOnDelete)
            // para não corromper saldos já debitados.
            $table->foreignId('consorcio_id')->nullable()->after('group_id')
                ->constrained('consorcios')->nullOnDelete();
            $table->index('consorcio_id');
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consorcio_id');
        });
    }
};
