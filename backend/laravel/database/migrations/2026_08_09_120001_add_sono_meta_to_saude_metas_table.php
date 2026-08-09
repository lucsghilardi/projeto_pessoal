<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Meta de sono por noite, em minutos (450 = 7h30). */
    public function up(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->unsignedSmallInteger('sono_meta_min')->nullable()->after('altura_cm');
        });
    }

    public function down(): void
    {
        Schema::table('saude_metas', function (Blueprint $table) {
            $table->dropColumn('sono_meta_min');
        });
    }
};
