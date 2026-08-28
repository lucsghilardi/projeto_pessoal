<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            // Mensagem editada por um contato vira aviso no seu próprio número.
            // Ligado por padrão pelo mesmo motivo do aviso de apagadas: não
            // gasta crédito de IA e só serve se chegar na hora.
            $table->boolean('aviso_edicoes_ativo')->default(true)->after('aviso_apagadas_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            $table->dropColumn('aviso_edicoes_ativo');
        });
    }
};
