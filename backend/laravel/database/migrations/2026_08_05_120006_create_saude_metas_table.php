<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saude_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('peso_meta_kg', 5, 2)->nullable();
            $table->date('data_alvo')->nullable();
            $table->unsignedSmallInteger('altura_cm')->nullable(); // para calcular IMC
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saude_metas');
    }
};
