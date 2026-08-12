<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            // Foto de comprovante no chat-consigo-mesmo vira lançamento financeiro.
            // Opt-in: capacidade nova e que mexe em dinheiro.
            $table->boolean('financeiro_ativo')->default(false)->after('calorias_texto_ia');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            $table->dropColumn('financeiro_ativo');
        });
    }
};
