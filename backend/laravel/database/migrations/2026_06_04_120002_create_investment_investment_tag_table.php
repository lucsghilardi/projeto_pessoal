<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_investment_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['investment_id', 'investment_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_investment_tag');
    }
};
