<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado da conversa do chat-consigo-mesmo: o assistente propõe e só grava
     * depois de confirmado, então precisa lembrar o que está pendente entre uma
     * mensagem e a próxima.
     *
     * Uma linha permanente por instância (nunca deletada, estado 'ocioso' = sem
     * pendência): a linha é o mutex que o ingest trava com lockForUpdate para
     * duas mensagens rápidas não abrirem duas propostas.
     */
    public function up(): void
    {
        Schema::create('whatsapp_conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instancia_id')->unique()->constrained('whatsapp_instancias')->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained('whatsapp_chats')->nullOnDelete();
            // ocioso|aguardando_tipo_midia|aguardando_confirmacao|aguardando_destino|aguardando_acao|processando
            $table->string('estado', 30)->default('ocioso');
            $table->string('fluxo', 20)->nullable(); // tarefa|refeicao|comprovante
            // Mensagem ORIGINAL que abriu o fluxo (não a da confirmação): é dela
            // que sai o horário da refeição e a chave de idempotência.
            $table->foreignId('mensagem_id')->nullable()->constrained('whatsapp_mensagens')->nullOnDelete();
            // Coluna própria (fora do payload) para varrer anexos órfãos em disco.
            $table->string('anexo_path')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('expira_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversas');
    }
};
