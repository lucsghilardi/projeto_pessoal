<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_instancias', function (Blueprint $table) {
            $table->id();
            // Uma instância (número conectado) por usuário.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('apelido', 60)->nullable();
            // Nome da instância na Evolution API.
            $table->string('instance_name', 120)->unique();
            // Telefone conectado (só dígitos), preenchido quando a sessão abre.
            $table->string('phone', 30)->nullable();
            $table->string('status', 20)->default('desconectado'); // desconectado|conectando|conectado
            $table->boolean('gtd_ativo')->default(true);
            $table->boolean('relatorio_diario_ativo')->default(true);
            $table->boolean('resumo_matinal_ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instancias');
    }
};
