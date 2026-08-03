<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_sugestoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('whatsapp_chats')->nullOnDelete();
            $table->foreignId('relatorio_id')->nullable()->constrained('whatsapp_relatorios')->nullOnDelete();
            $table->string('origem', 20); // relatorio|analise|gtd
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->string('prioridade', 20)->default('medium'); // low|medium|high|urgent
            $table->date('due_date')->nullable();
            // Trecho da conversa que motivou a sugestão.
            $table->text('contexto')->nullable();
            $table->string('status', 20)->default('pendente'); // pendente|aceita|descartada
            // Tarefa criada no kanban ao aceitar.
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_sugestoes');
    }
};
