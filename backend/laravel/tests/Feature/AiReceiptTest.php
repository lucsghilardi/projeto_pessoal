<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\FinanceCategory;
use App\Models\Payable;
use App\Models\User;
use App\Services\Finance\ReceiptEntryService;
use App\Services\ReceiptAI\ReceiptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Trava o comportamento do lançamento de comprovante avulso: a gravação saiu do
 * AiReceiptController para o ReceiptEntryService (reusado pelo WhatsApp) e o
 * resultado visto pelo painel não pode mudar.
 */
class AiReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmar_comprovante_em_conta_debita_o_saldo(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $conta = $this->conta($user, 'Itaú', 1000.00);
        $categoria = $this->categoria($user, 'Mercado');
        $path = $this->comprovante($user);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/finance/ai-receipt/confirm', [
                'destination' => 'conta',
                'receipt_path' => $path,
                'description' => 'Supermercado Zaffari',
                'amount' => 187.40,
                'date' => '2026-08-11',
                'category_id' => $categoria->id,
                'bank_account_id' => $conta->id,
            ])
            ->assertCreated()
            ->assertJsonPath('created', 1);

        $payable = Payable::where('user_id', $user->id)->sole();
        $this->assertTrue((bool) $payable->is_paid);
        $this->assertSame('2026-08-11', $payable->paid_at->toDateString());
        $this->assertSame('avulsa', $payable->kind);
        $this->assertSame($conta->id, $payable->bank_account_id);
        $this->assertSame($categoria->id, $payable->category_id);
        $this->assertSame($path, $payable->receipt_path);
        $this->assertNotNull($payable->import_fingerprint);

        $this->assertEquals(812.60, $conta->fresh()->balance);
    }

    public function test_confirmar_comprovante_parcelado_no_cartao_abre_uma_fatura_por_parcela(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $cartao = $this->cartao($user);
        $path = $this->comprovante($user);

        $this->withHeader('Authorization', $token)
            ->postJson('/api/finance/ai-receipt/confirm', [
                'destination' => 'cartao',
                'receipt_path' => $path,
                'description' => 'Notebook',
                'amount' => 500.00,
                'date' => '2026-08-11',
                'credit_card_id' => $cartao->id,
                'installments_total' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('created', 3);

        $parcelas = CreditCardTransaction::where('credit_card_id', $cartao->id)
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(3, $parcelas);
        $this->assertSame([1, 2, 3], $parcelas->pluck('installment_number')->all());
        $this->assertSame([3, 3, 3], $parcelas->pluck('installments_total')->all());
        // Todas no mesmo grupo, cada uma numa fatura diferente e consecutiva.
        $this->assertCount(1, $parcelas->pluck('group_id')->unique());
        $this->assertSame(
            ['2026-09', '2026-10', '2026-11'],
            $parcelas->map(fn ($p) => $p->invoice->reference_month)->all(),
        );
        $this->assertEquals(500.00, $parcelas->first()->amount);
    }

    public function test_comprovante_identico_e_recusado_como_duplicado(): void
    {
        $user = User::factory()->create();
        $token = $this->bearerTokenFor($user);
        $conta = $this->conta($user, 'Itaú', 1000.00);
        $path = $this->comprovante($user);

        $payload = [
            'destination' => 'conta',
            'receipt_path' => $path,
            'description' => 'Padaria',
            'amount' => 25.00,
            'date' => '2026-08-11',
            'bank_account_id' => $conta->id,
        ];

        $this->withHeader('Authorization', $token)
            ->postJson('/api/finance/ai-receipt/confirm', $payload)
            ->assertCreated();

        $this->withHeader('Authorization', $token)
            ->postJson('/api/finance/ai-receipt/confirm', $payload)
            ->assertStatus(422);

        $this->assertSame(1, Payable::where('user_id', $user->id)->count());
        $this->assertEquals(975.00, $conta->fresh()->balance);
    }

    public function test_comprovante_de_outro_usuario_nao_e_aceito(): void
    {
        $user = User::factory()->create();
        $dono = User::factory()->create();
        $conta = $this->conta($user, 'Itaú', 1000.00);
        $pathAlheio = $this->comprovante($dono);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/finance/ai-receipt/confirm', [
                'destination' => 'conta',
                'receipt_path' => $pathAlheio,
                'description' => 'Padaria',
                'amount' => 25.00,
                'date' => '2026-08-11',
                'bank_account_id' => $conta->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Payable::count());
        $this->assertEquals(1000.00, $conta->fresh()->balance);
    }

    /**
     * O controller barra conta alheia na validação; o service é chamado direto
     * pelo WhatsApp, sem FormRequest, então precisa se defender sozinho.
     */
    public function test_service_recusa_debitar_conta_de_outro_usuario(): void
    {
        $user = User::factory()->create();
        $contaAlheia = $this->conta(User::factory()->create(), 'Itaú', 1000.00);
        $path = $this->comprovante($user);

        $this->expectException(RuntimeException::class);

        try {
            app(ReceiptEntryService::class)->lancar($user->id, [
                'destination' => 'conta',
                'description' => 'Padaria',
                'amount' => 25.00,
                'date' => '2026-08-11',
                'bank_account_id' => $contaAlheia->id,
            ], $path);
        } finally {
            $this->assertSame(0, Payable::count());
            $this->assertEquals(1000.00, $contaAlheia->fresh()->balance);
        }
    }

    /**
     * Data no formato do cupom brasileiro não pode virar outro mês. Carbon lê
     * "11/08/2026" como mês/dia/ano (8 de novembro) — o lançamento existia, mas
     * sumia da tela do mês corrente.
     *
     * @param  array{0: string|null, 1: string|null}  $caso  [o que a IA devolveu, o que deve ser gravado]
     */
    #[DataProvider('datasDeComprovante')]
    public function test_data_do_comprovante_e_normalizada_para_iso(?string $daIa, ?string $esperado): void
    {
        $user = User::factory()->create();
        $categorias = collect([['id' => 1, 'name' => 'Mercado']]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [[
                'type' => 'tool_use',
                'input' => [
                    'document_type' => 'comprovante',
                    'items' => [[
                        'amount' => 5.50,
                        'purchase_date' => $daIa,
                        'description' => 'Coca-Cola',
                        'payment_method' => 'pix',
                        'confidence' => 'alta',
                    ]],
                ],
            ]]]),
        ]);
        config(['services.anthropic.key' => 'k']);

        $resultado = app(ReceiptParser::class)->parse('bytes', 'image/jpeg', 'jpg', $categorias);

        $this->assertSame($esperado, $resultado['items'][0]['purchase_date']);
        unset($user);
    }

    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function datasDeComprovante(): array
    {
        return [
            'iso passa direto' => ['2026-08-11', '2026-08-11'],
            'cupom com barra' => ['11/08/2026', '2026-08-11'],
            'cupom com ponto' => ['11.08.2026', '2026-08-11'],
            'cupom com hifen' => ['11-08-2026', '2026-08-11'],
            'dia sem zero' => ['1/8/2026', '2026-08-01'],
            'data impossivel' => ['31/02/2026', null],
            'lixo vira nulo' => ['ontem', null],
            'ausente' => [null, null],
        ];
    }

    public function test_confirm_recusa_data_fora_do_formato_iso(): void
    {
        $user = User::factory()->create();
        $conta = $this->conta($user, 'Itaú', 1000.00);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->postJson('/api/finance/ai-receipt/confirm', [
                'destination' => 'conta',
                'receipt_path' => $this->comprovante($user),
                'description' => 'Coca-Cola',
                'amount' => 5.50,
                'date' => '11/08/2026',
                'bank_account_id' => $conta->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertSame(0, Payable::count());
        $this->assertEquals(1000.00, $conta->fresh()->balance);
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }

    private function conta(User $user, string $nome, float $saldo): BankAccount
    {
        return BankAccount::create(['user_id' => $user->id, 'name' => $nome, 'balance' => $saldo]);
    }

    private function categoria(User $user, string $nome): FinanceCategory
    {
        return FinanceCategory::create([
            'user_id' => $user->id,
            'name' => $nome,
            'kind' => 'despesa',
            'color' => '#64748b',
        ]);
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

    private function comprovante(User $user): string
    {
        Storage::fake('local');
        $path = "receipts/{$user->id}/cupom.jpg";
        Storage::disk('local')->put($path, 'binario');

        return $path;
    }
}
