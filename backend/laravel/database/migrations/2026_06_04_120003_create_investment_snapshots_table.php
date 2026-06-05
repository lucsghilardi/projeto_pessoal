<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['investment_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_snapshots');
    }
};
