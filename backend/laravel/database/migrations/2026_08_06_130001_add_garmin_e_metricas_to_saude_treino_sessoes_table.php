<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Métricas da sessão de musculação (para o balanço calórico) e rastro da
     * importação do Garmin (para não duplicar em re-sincronizações).
     *
     * O UNIQUE(user_id, data) da tabela continua valendo: dois treinos de força
     * no mesmo dia colapsam na mesma linha, vencendo o último.
     */
    public function up(): void
    {
        Schema::table('saude_treino_sessoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('duracao_min')->nullable()->after('data');
            $table->unsignedSmallInteger('calorias')->nullable()->after('duracao_min');
            $table->string('origem', 20)->default('manual')->after('calorias'); // manual|garmin
            $table->unsignedBigInteger('garmin_activity_id')->nullable()->unique()->after('origem');
        });
    }

    public function down(): void
    {
        Schema::table('saude_treino_sessoes', function (Blueprint $table) {
            $table->dropColumn(['duracao_min', 'calorias', 'origem', 'garmin_activity_id']);
        });
    }
};
