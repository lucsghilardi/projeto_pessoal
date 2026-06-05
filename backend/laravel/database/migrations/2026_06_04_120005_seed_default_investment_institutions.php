<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cria as instituicoes padrao para os usuarios existentes, alem de registrar
     * as instituicoes ja usadas em investimentos (para nao perde-las no Select).
     */
    public function up(): void
    {
        $defaults = ['Itaú', 'Inter', 'BTG', 'Nubank'];
        $now = now();

        foreach (DB::table('users')->pluck('id') as $userId) {
            $names = $defaults;

            $used = DB::table('investments')
                ->where('user_id', $userId)
                ->whereNotNull('institution')
                ->distinct()
                ->pluck('institution')
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->all();

            foreach (array_unique([...$names, ...$used]) as $name) {
                DB::table('investment_institutions')->insertOrIgnore([
                    'user_id' => $userId,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nao remove dados de instituicoes para evitar perda de cadastros do usuario.
    }
};
