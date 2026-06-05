<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained()->cascadeOnDelete();
            $table->string('reference_month', 7); // YYYY-MM (mês do vencimento)
            $table->date('closing_date');
            $table->date('due_date');
            $table->timestamps();

            // Status (aberta | parcial | paga) é calculado a partir dos pagamentos vs total.
            $table->unique(['credit_card_id', 'reference_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_invoices');
    }
};
