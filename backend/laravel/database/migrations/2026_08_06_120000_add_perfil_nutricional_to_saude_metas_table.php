<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            // Perfil para TMB/TDEE (Mifflin-St Jeor exige sexo + idade + altura + peso).
            $table->string('sexo', 1)->nullable()->after('altura_cm'); // M|F
            $table->date('data_nascimento')->nullable()->after('sexo');
            $table->string('nivel_atividade', 20)->nullable()->default('sedentario')->after('data_nascimento');
            // Overrides manuais das metas calculadas.
            $table->unsignedSmallInteger('calorias_alvo')->nullable()->after('nivel_atividade');
            $table->unsignedSmallInteger('proteinas_alvo_g')->nullable()->after('calorias_alvo');
        });
    }

    public function down(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->dropColumn(['sexo', 'data_nascimento', 'nivel_atividade', 'calorias_alvo', 'proteinas_alvo_g']);
        });
    }
};
