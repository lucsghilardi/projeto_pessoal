<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instancia_id')->constrained('whatsapp_instancias')->cascadeOnDelete();
            // Chave canônica do chat: telefone (só dígitos) para conversa direta,
            // JID (xxx@g.us / xxx@lid) para grupos e contatos sem telefone.
            $table->string('chave', 120);
            $table->string('phone', 30)->nullable();
            $table->string('chat_lid', 120)->nullable();
            $table->string('chat_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->text('photo')->nullable();
            $table->boolean('is_group')->default(false);
            // Contato marcado para monitoramento prioritário pela IA.
            $table->boolean('monitorado')->default(false);
            $table->timestamp('monitorado_em')->nullable();
            $table->boolean('arquivado')->default(false);
            $table->string('last_message_id', 191)->nullable();
            $table->text('last_message_text')->nullable();
            $table->boolean('last_message_from_me')->default(false);
            $table->timestamp('last_message_at')->nullable();
            // Última mensagem RECEBIDA (deles) — base do score de atenção.
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['instancia_id', 'chave']);
            $table->index(['instancia_id', 'last_message_at']);
            $table->index(['instancia_id', 'monitorado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chats');
    }
};
