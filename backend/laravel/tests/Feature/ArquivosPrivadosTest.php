<?php

namespace Tests\Feature;

use App\Models\Consorcio;
use App\Models\Payable;
use App\Models\User;
use App\Services\ReceiptAI\ReceiptParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Contenção dos anexos privados (comprovante, proposta de consórcio).
 *
 * Duas regras que o painel não pode perder:
 *  - o caminho do arquivo enviado pelo cliente fica DENTRO da pasta da conta,
 *    inclusive quando ele tenta sair dela com "..";
 *  - o que sai pelo download é declarado pela extensão gravada, não pelo que o
 *    finfo achar do conteúdo — senão um "comprovante" com HTML dentro volta
 *    como text/html e roda na origem do painel.
 */
class ArquivosPrivadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_path_com_travessia_nao_alcanca_a_pasta_de_outro_usuario(): void
    {
        Storage::fake('local');

        $eu = User::factory()->create();
        $outro = User::factory()->create();

        // O alvo: um comprovante que é do outro usuário.
        Storage::disk('local')->put("receipts/{$outro->id}/segredo.jpg", 'binario');
        // E um meu, só para o prefixo do caminho existir de verdade.
        Storage::disk('local')->put("receipts/{$eu->id}/meu.jpg", 'binario');

        $this->withHeader('Authorization', $this->bearerTokenFor($eu))
            ->postJson('/api/finance/ai-receipt/confirm', [
                'destination' => 'conta',
                'receipt_path' => "receipts/{$eu->id}/../{$outro->id}/segredo.jpg",
                'description' => 'Tentativa',
                'amount' => 10,
                'date' => '2026-09-01',
                'bank_account_id' => null,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Payable::count());
    }

    public function test_comprovante_com_html_dentro_desce_com_o_tipo_da_extensao(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "receipts/{$user->id}/cupom.jpg";
        Storage::disk('local')->put($path, '<html><script>alert(1)</script></html>');

        $payable = Payable::create([
            'user_id' => $user->id,
            'description' => 'Mercado',
            'amount' => 10,
            'due_date' => '2026-09-01',
            'kind' => 'avulsa',
            'receipt_path' => $path,
        ]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->get("/api/finance/receipts/payable/{$payable->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_extrato_csv_desce_como_anexo_em_vez_de_ser_interpretado(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "receipts/{$user->id}/extrato.csv";
        Storage::disk('local')->put($path, "data;valor\n2026-09-01;10,00");

        $payable = Payable::create([
            'user_id' => $user->id,
            'description' => 'Importado',
            'amount' => 10,
            'due_date' => '2026-09-01',
            'kind' => 'avulsa',
            'receipt_path' => $path,
        ]);

        $resposta = $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->get("/api/finance/receipts/payable/{$payable->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');

        $this->assertStringStartsWith('attachment', (string) $resposta->headers->get('Content-Disposition'));
    }

    public function test_proposta_de_consorcio_com_html_dentro_e_recusada_no_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $consorcio = $this->consorcio($user);

        $this->comoUsuario($user)
            ->post("/api/consorcios/{$consorcio->id}/proposta", [
                'file' => $this->uploadReal(
                    'proposta.pdf',
                    '<html><body><script>alert(1)</script></body></html>',
                    'application/pdf',
                ),
            ])
            ->assertStatus(422);

        $this->assertNull($consorcio->fresh()->proposta_path);
    }

    /**
     * A trava do teste acima só vale se o arquivo de verdade continuar entrando
     * — uma regra de mimetype errada barraria a proposta legítima em silêncio.
     */
    public function test_proposta_de_consorcio_legitima_continua_entrando(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $consorcio = $this->consorcio($user);

        $this->comoUsuario($user)
            ->post("/api/consorcios/{$consorcio->id}/proposta", [
                'file' => $this->uploadReal('proposta.pdf', $this->pdfMinimo(), 'application/pdf'),
            ])
            ->assertOk();

        $this->assertNotNull($consorcio->fresh()->proposta_path);
    }

    public function test_comprovante_com_html_dentro_e_recusado_no_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $this->comoUsuario($user)
            ->post('/api/finance/ai-receipt/parse', [
                'file' => $this->uploadReal(
                    'cupom.jpg',
                    '<html><body><script>alert(1)</script></body></html>',
                    'image/jpeg',
                ),
            ])
            ->assertStatus(422);
    }

    /**
     * Extrato em CSV é o caso que mais tensiona a lista de mimetypes: o finfo
     * ora diz text/csv, ora text/plain. Se cair fora da lista, a importação de
     * extrato para de funcionar.
     */
    public function test_extrato_csv_legitimo_continua_entrando(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $parser = Mockery::mock(ReceiptParser::class);
        $parser->shouldReceive('parse')->andReturn([
            'document_type' => 'extrato',
            'card_last_four' => null,
            'items' => [],
        ]);
        $this->app->instance(ReceiptParser::class, $parser);

        $this->comoUsuario($user)
            ->post('/api/finance/ai-receipt/parse', [
                'file' => $this->uploadReal(
                    'extrato.csv',
                    "data;descricao;valor\n2026-09-01;Mercado;-120,00\n2026-09-02;Posto;-90,00\n",
                    'text/csv',
                ),
            ])
            ->assertOk();
    }

    public function test_proposta_desce_com_o_tipo_da_extensao_gravada(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "consorcios/{$user->id}/proposta.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 conteudo');

        $consorcio = $this->consorcio($user);
        $consorcio->update(['proposta_path' => $path]);

        $this->withHeader('Authorization', $this->bearerTokenFor($user))
            ->get("/api/consorcios/{$consorcio->id}/proposta")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function bearerTokenFor(User $user): string
    {
        return 'Bearer '.Auth::guard('api')->login($user);
    }

    /**
     * Upload é multipart, então não dá para usar `postJson`; sem o Accept o
     * Laravel responde erro de validação com redirect em vez de 422 — é o
     * mesmo Accept que o proxy do Next manda em produção.
     */
    private function comoUsuario(User $user): self
    {
        return $this->withHeaders([
            'Authorization' => $this->bearerTokenFor($user),
            'Accept' => 'application/json',
        ]);
    }

    /**
     * UploadedFile de verdade: o `UploadedFile::fake()` resolve o mime pelo
     * NOME do arquivo, então com ele um HTML chamado .pdf passaria por PDF e o
     * teste não provaria nada. Aqui o conteúdo é gravado em disco e o
     * `getMimeType()` (que é o que a regra `mimetypes` consulta) sai do finfo,
     * como acontece num upload real. O terceiro argumento é o mime DECLARADO
     * pelo cliente — justamente a parte que não se pode acreditar.
     */
    private function uploadReal(string $nome, string $conteudo, string $mimeDeclarado): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, $mimeDeclarado, null, true);
    }

    private function pdfMinimo(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function consorcio(User $user): Consorcio
    {
        return Consorcio::create([
            'user_id' => $user->id,
            'nome' => 'Consórcio Casa',
            'tipo' => 'novo',
        ]);
    }
}
