<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            $table->boolean('is_received')->default(false);
            $table->date('received_at')->nullable();
            $table->string('kind')->default('avulsa'); // avulsa | fixa
            $table->uuid('group_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_date']);
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
