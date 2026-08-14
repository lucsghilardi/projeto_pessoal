<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_mensagens', function (Blueprint $table) {
            // Quando o contato apagou a mensagem ("apagar para todos"). Guarda o
            // conteúdo original — a Evolution não reenvia o que foi apagado — e
            // serve de dedupe: o mesmo revoke chega em dois formatos de evento
            // (messages.delete e messages.upsert com protocolMessage).
            $table->timestamp('apagada_em')->nullable()->after('origem');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_mensagens', function (Blueprint $table) {
            $table->dropColumn('apagada_em');
        });
    }
};
