<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cria contas bancarias e categorias padrao para os usuarios existentes.
     */
    public function up(): void
    {
        $now = now();

        $accounts = ['Itaú', 'Nubank', 'Inter'];

        $expenseCategories = [
            'Moradia' => '#1d4ed8',
            'Água/Luz/Gás' => '#0891b2',
            'Internet/Telefone' => '#0d9488',
            'Mercado' => '#16a34a',
            'Restaurantes/Delivery' => '#65a30d',
            'Transporte' => '#ca8a04',
            'Veículo' => '#b45309',
            'Filho' => '#db2777',
            'Saúde' => '#dc2626',
            'Academia' => '#e11d48',
            'Lazer & Namoro' => '#9333ea',
            'Assinaturas' => '#7c3aed',
            'Cartão de crédito' => '#475569',
            'Educação' => '#2563eb',
            'Vestuário' => '#c026d3',
            'Impostos' => '#78716c',
            'Outros' => '#64748b',
        ];

        $incomeCategories = [
            'Salário' => '#16a34a',
            'Renda extra' => '#0d9488',
            'Reembolsos' => '#0891b2',
            'Rendimentos' => '#2563eb',
            'Outros' => '#64748b',
        ];

        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach ($accounts as $name) {
                DB::table('bank_accounts')->insertOrIgnore([
                    'user_id' => $userId,
                    'name' => $name,
                    'balance' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($expenseCategories as $name => $color) {
                DB::table('finance_categories')->insertOrIgnore([
                    'user_id' => $userId,
                    'name' => $name,
                    'kind' => 'despesa',
                    'color' => $color,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($incomeCategories as $name => $color) {
                DB::table('finance_categories')->insertOrIgnore([
                    'user_id' => $userId,
                    'name' => $name,
                    'kind' => 'receita',
                    'color' => $color,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Nao remove dados para evitar perda de cadastros do usuario.
    }
};
