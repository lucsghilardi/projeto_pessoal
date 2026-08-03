<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
            $table->foreignId('instancia_id')->constrained('whatsapp_instancias')->cascadeOnDelete();
            $table->string('message_id', 191)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('from_me')->default(false);
            $table->string('sender_name')->nullable();
            $table->string('tipo', 30)->default('text'); // text|image|audio|video|document|sticker|location|contact
            $table->text('texto')->nullable();
            $table->text('caption')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->string('media_filename')->nullable();
            $table->string('status', 30)->nullable(); // RECEIVED|SENT|DELIVERED|READ|FAILED
            // Epoch em milissegundos (ordenação estável independente de fuso).
            $table->unsignedBigInteger('momment')->index();
            $table->string('quoted_message_id', 191)->nullable();
            $table->text('quoted_texto')->nullable();
            // 'sistema' para mensagens enviadas pela própria aplicação (relatórios,
            // confirmações GTD) — o ingest usa isso para não reprocessá-las.
            $table->string('origem', 20)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // No Postgres, NULLs não colidem em índice único.
            $table->unique(['instancia_id', 'message_id']);
            $table->index(['chat_id', 'momment']);
            $table->index(['instancia_id', 'from_me', 'momment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensagens');
    }
};
