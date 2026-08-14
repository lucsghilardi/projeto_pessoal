<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\FinanceCategory;
use App\Models\InvestmentInstitution;
use App\Models\SaudeTreino;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dados iniciais de uma conta nova.
 *
 * Antes isso vivia nas migrations de seed (`seed_default_finance_data`,
 * `seed_default_investment_institutions`, `seed_default_saude_data`), que rodam
 * `foreach (users)` uma única vez — quem fosse criado depois nascia com o painel
 * vazio e travado: sem categoria não se lança despesa, e sem conta bancária o
 * `PayableController::pay` responde 422 porque `bank_account_id` é obrigatório.
 *
 * Fica de fora tudo que é pessoal do dono: suplementos e exercícios não são
 * "padrão", são a rotina de uma pessoa (as fichas antigas trazem até zonas de FC
 * calculadas para uma idade específica). Pior: `EnviarLembretesSuplementos` roda
 * a cada 5 min sobre todos os usuários e mandaria no WhatsApp de um parente o
 * lembrete do stack de outra pessoa.
 *
 * Também não provisiona `saude_metas`, `user_stats`, colunas de tarefas nem o
 * projeto do GTD: todos já nascem sob demanda (`updateOrCreate` no MetaController,
 * `firstOrCreate` no GamificationService, `ProjectController::store`,
 * `WhatsappTaskBridge::colunaPadrao`).
 */
class UserProvisioningService
{
    /** Nome → cor. Mantido igual ao seed original, menos os rótulos pessoais. */
    private const CATEGORIAS_DESPESA = [
        'Moradia' => '#1d4ed8',
        'Água/Luz/Gás' => '#0891b2',
        'Internet/Telefone' => '#0d9488',
        'Mercado' => '#16a34a',
        'Restaurantes/Delivery' => '#65a30d',
        'Transporte' => '#ca8a04',
        'Veículo' => '#b45309',
        'Filhos' => '#db2777',
        'Saúde' => '#dc2626',
        'Academia' => '#e11d48',
        'Lazer' => '#9333ea',
        'Assinaturas' => '#7c3aed',
        'Cartão de crédito' => '#475569',
        'Educação' => '#2563eb',
        'Vestuário' => '#c026d3',
        'Impostos' => '#78716c',
        'Outros' => '#64748b',
    ];

    private const CATEGORIAS_RECEITA = [
        'Salário' => '#16a34a',
        'Renda extra' => '#0d9488',
        'Reembolsos' => '#0891b2',
        'Rendimentos' => '#2563eb',
        'Outros' => '#64748b',
    ];

    private const INSTITUICOES = ['Itaú', 'Inter', 'BTG', 'Nubank'];

    /**
     * Uma conta genérica em vez dos bancos do dono: serve só para destravar a
     * baixa de contas a pagar/receber, e o usuário renomeia na tela de Contas.
     */
    private const CONTA_PADRAO = 'Conta principal';

    /** Fichas vazias — os exercícios ficam por conta de quem treina. */
    private const TREINOS = ['Treino A' => 1, 'Treino B' => 2];

    /**
     * Idempotente: pode rodar de novo em quem já tem dados sem duplicar nada
     * (todas as tabelas envolvidas têm unique por usuário + nome). É o que
     * permite o `usuarios:provisionar` cobrir contas antigas.
     */
    public function provisionar(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->criarCategorias($user);
            $this->criarConta($user);
            $this->criarInstituicoes($user);
            $this->criarTreinos($user);
        });
    }

    private function criarCategorias(User $user): void
    {
        $mapa = [
            'despesa' => self::CATEGORIAS_DESPESA,
            'receita' => self::CATEGORIAS_RECEITA,
        ];

        foreach ($mapa as $kind => $categorias) {
            foreach ($categorias as $name => $color) {
                FinanceCategory::firstOrCreate(
                    ['user_id' => $user->id, 'name' => $name, 'kind' => $kind],
                    ['color' => $color],
                );
            }
        }
    }

    private function criarConta(User $user): void
    {
        BankAccount::firstOrCreate(
            ['user_id' => $user->id, 'name' => self::CONTA_PADRAO],
            ['balance' => 0],
        );
    }

    private function criarInstituicoes(User $user): void
    {
        foreach (self::INSTITUICOES as $name) {
            InvestmentInstitution::firstOrCreate([
                'user_id' => $user->id,
                'name' => $name,
            ]);
        }
    }

    private function criarTreinos(User $user): void
    {
        foreach (self::TREINOS as $nome => $posicao) {
            SaudeTreino::firstOrCreate(
                ['user_id' => $user->id, 'nome' => $nome],
                ['tipo' => 'musculacao', 'posicao' => $posicao],
            );
        }
    }
}
