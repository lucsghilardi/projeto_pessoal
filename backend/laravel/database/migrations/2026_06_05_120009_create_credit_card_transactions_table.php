<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 15, 2); // valor por parcela
            $table->date('purchase_date');
            $table->unsignedSmallInteger('installment_number')->nullable();
            $table->unsignedSmallInteger('installments_total')->nullable();
            $table->uuid('group_id')->nullable();
            $table->timestamps();

            $table->index('credit_card_invoice_id');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_transactions');
    }
};
