<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            // Foto de prato no chat-consigo-mesmo vira refeição (módulo Saúde).
            $table->boolean('calorias_foto_ativo')->default(true)->after('gtd_ativo');
            // Texto no chat-consigo-mesmo: IA decide entre refeição e tarefa GTD.
            $table->boolean('calorias_texto_ia')->default(true)->after('calorias_foto_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instancias', function (Blueprint $table) {
            $table->dropColumn(['calorias_foto_ativo', 'calorias_texto_ia']);
        });
    }
};
