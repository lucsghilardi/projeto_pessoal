<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            // Mensagem apagada por um contato vira aviso no seu próprio número.
            // Ligado por padrão: não gasta crédito de IA, ao contrário dos
            // relatórios, e é o tipo de coisa que só serve se chegar na hora.
            $table->boolean('aviso_apagadas_ativo')->default(true)->after('financeiro_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            $table->dropColumn('aviso_apagadas_ativo');
        });
    }
};
