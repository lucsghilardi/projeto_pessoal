<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\CreditCardInvoicePayment;
use App\Models\CreditCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Trava a previsão de faturas exibida em Contas a pagar. A regra sensível é o
 * ciclo: a fatura só está fechada no dia SEGUINTE ao corte, porque uma compra
 * feita no dia do corte ainda entra nela (CreditCard::resolveInvoiceWindow).
 * Sem Carbon::setTestNow() esses testes virariam flaky todo mês.
 */
class CreditCardInvoiceForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixa o fuso para o "hoje" não depender do ambiente que roda a suite.
        config(['finance.timezone' => 'America/Sao_Paulo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cartao_ativo_sem_fatura_aparece_zerado_e_nao_cria_registro(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $this->cartao($user);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.invoice_id', null)
            ->assertJsonPath('0.card_name', 'Nubank')
            ->assertJsonPath('0.total', 0)
            ->assertJsonPath('0.status', 'aberta')
            // due_day 1 e closing_day 20: a fatura que vence em 01/09 fecha em 20/08.
            ->assertJsonPath('0.closing_date', '2026-08-20')
            ->assertJsonPath('0.due_date', '2026-09-01');

        // Listar contas a pagar não pode gravar nada (invoiceForReferenceMonth cria).
        $this->assertSame(0, CreditCardInvoice::count());
    }

    public function test_no_dia_do_corte_a_fatura_ainda_nao_esta_fechada(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $this->cartao($user);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonPath('0.closing_date', '2026-08-20')
            ->assertJsonPath('0.is_closed', false);
    }

    public function test_no_dia_seguinte_ao_corte_a_fatura_esta_fechada(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00');

        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $this->cartao($user);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonPath('0.is_closed', true);
    }

    public function test_datas_da_fatura_gravada_vencem_o_recalculo_do_cartao(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $cartao = $this->cartao($user);

        $this->fatura($user, $cartao, '2026-09', '2026-08-20', '2026-09-01');

        // Usuário muda o cartão depois: as faturas antigas não podem ser reescritas,
        // senão a mesma fatura mostraria datas diferentes aqui e na tela do cartão.
        $cartao->update(['closing_day' => 5, 'due_day' => 15]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonPath('0.closing_date', '2026-08-20')
            ->assertJsonPath('0.due_date', '2026-09-01');
    }

    public function test_totais_somam_lancamentos_e_pagamentos_da_fatura(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $cartao = $this->cartao($user);
        $fatura = $this->fatura($user, $cartao, '2026-09', '2026-08-20', '2026-09-01');

        $this->lancamento($user, $cartao, $fatura, 'Mercado', 120.50);
        $this->lancamento($user, $cartao, $fatura, 'Farmácia', 79.50);

        CreditCardInvoicePayment::create([
            'user_id' => $user->id,
            'credit_card_invoice_id' => $fatura->id,
            'bank_account_id' => null,
            'amount' => 50.00,
            'paid_at' => '2026-09-01',
        ]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonPath('0.invoice_id', $fatura->id)
            ->assertJsonPath('0.total', 200)
            ->assertJsonPath('0.paid_total', 50)
            ->assertJsonPath('0.remaining', 150)
            ->assertJsonPath('0.status', 'parcial');
    }

    public function test_fatura_quitada_fica_com_status_paga(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $cartao = $this->cartao($user);
        $fatura = $this->fatura($user, $cartao, '2026-09', '2026-08-20', '2026-09-01');

        $this->lancamento($user, $cartao, $fatura, 'Mercado', 200.00);

        CreditCardInvoicePayment::create([
            'user_id' => $user->id,
            'credit_card_invoice_id' => $fatura->id,
            'bank_account_id' => null,
            'amount' => 200.00,
            'paid_at' => '2026-09-01',
        ]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonPath('0.remaining', 0)
            ->assertJsonPath('0.status', 'paga');
    }

    public function test_cartao_inativo_com_fatura_no_mes_continua_aparecendo(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $cartao = $this->cartao($user);
        $fatura = $this->fatura($user, $cartao, '2026-09', '2026-08-20', '2026-09-01');
        $this->lancamento($user, $cartao, $fatura, 'Mercado', 90.00);

        // Desativar o cartão não apaga a dívida que já existe.
        $cartao->update(['is_active' => false]);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.card_is_active', false)
            ->assertJsonPath('0.total', 90);

        // Já num mês sem fatura, o cartão inativo some.
        $this->withHeader('Authorization', $token)
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-10')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_nao_lista_faturas_de_outro_usuario(): void
    {
        $user = User::factory()->create();
        $outro = User::factory()->create();
        $this->cartao($outro);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->getJson('/api/finance/credit-cards/invoices/forecast?month=2026-09')
            ->assertOk()
            ->assertJsonCount(0);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }

    private function cartao(User $user): CreditCard
    {
        return CreditCard::create([
            'user_id' => $user->id,
            'name' => 'Nubank',
            'brand' => 'Mastercard',
            'last_four' => '1234',
            'limit' => 5000,
            'closing_day' => 20,
            'due_day' => 1,
            'is_active' => true,
        ]);
    }

    private function fatura(User $user, CreditCard $cartao, string $ref, string $corte, string $vencimento): CreditCardInvoice
    {
        return CreditCardInvoice::create([
            'user_id' => $user->id,
            'credit_card_id' => $cartao->id,
            'reference_month' => $ref,
            'closing_date' => $corte,
            'due_date' => $vencimento,
        ]);
    }

    private function lancamento(
        User $user,
        CreditCard $cartao,
        CreditCardInvoice $fatura,
        string $descricao,
        float $valor,
    ): CreditCardTransaction {
        return CreditCardTransaction::create([
            'user_id' => $user->id,
            'credit_card_id' => $cartao->id,
            'credit_card_invoice_id' => $fatura->id,
            'category_id' => null,
            'description' => $descricao,
            'amount' => $valor,
            'purchase_date' => '2026-08-10',
        ]);
    }
}
