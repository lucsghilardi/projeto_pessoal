<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('last_four', 4)->nullable();
            $table->decimal('limit', 15, 2)->nullable();
            $table->unsignedTinyInteger('closing_day'); // dia de corte (1-31)
            $table->unsignedTinyInteger('due_day');      // dia de vencimento (1-31)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
