<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->string('import_fingerprint', 40)->nullable()->after('receipt_path');
            $table->index(['user_id', 'import_fingerprint']);
        });

        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->string('import_fingerprint', 40)->nullable()->after('receipt_path');
            $table->index(['credit_card_id', 'import_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'import_fingerprint']);
            $table->dropColumn('import_fingerprint');
        });

        Schema::table('credit_card_transactions', function (Blueprint $table) {
            $table->dropIndex(['credit_card_id', 'import_fingerprint']);
            $table->dropColumn('import_fingerprint');
        });
    }
};
