<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_mensagens', function (Blueprint $table) {
            // Primeira versão do texto, congelada na primeira edição. A coluna
            // `texto` passa a acompanhar o que o celular mostra hoje; sem esta
            // aqui o que a pessoa escreveu antes se perderia, e a Evolution roda
            // com DATABASE_SAVE_DATA_HISTORIC desligado — não há de onde tirar.
            $table->text('texto_original')->nullable()->after('texto');
            // Quando a mensagem foi editada pela última vez.
            $table->timestamp('editada_em')->nullable()->after('apagada_em');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_mensagens', function (Blueprint $table) {
            $table->dropColumn(['texto_original', 'editada_em']);
        });
    }
};
