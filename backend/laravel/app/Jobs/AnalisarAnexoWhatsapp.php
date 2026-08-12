<?php

namespace App\Jobs;

use App\Models\FinanceCategory;
use App\Models\WhatsappConversa;
use App\Models\WhatsappMensagem;
use App\Services\Finance\FinanceDestinoResolver;
use App\Services\ReceiptAI\ReceiptParser;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Whatsapp\WhatsappConversaService;
use App\Services\Whatsapp\WhatsappIngestService;
use App\Services\Whatsapp\WhatsappPropostaTexto;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Lê a foto que está em staging (refeição por visão, ou comprovante pelo
 * ReceiptParser) e devolve uma PROPOSTA no chat. Nada é gravado aqui — quem
 * grava é o ProcessarMensagemPessoal, depois do seu "sim".
 *
 * tries=1 de propósito: um turno repetido pela metade (analisou, não respondeu)
 * mandaria duas propostas. Falhou, o failed() destrava e avisa.
 */
class AnalisarAnexoWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $mensagemId, public string $fluxo) {}

    public function handle(
        WhatsappConversaService $conversas,
        SaudeNutricaoAI $nutricaoIa,
        ReceiptParser $receiptParser,
        FinanceDestinoResolver $destinos,
        WhatsappPropostaTexto $texto,
        WhatsappSender $sender,
    ): void {
        $mensagem = WhatsappMensagem::with('instancia.user')->find($this->mensagemId);
        $user = $mensagem?->instancia?->user;
        if ($user === null) {
            return;
        }

        $instancia = $mensagem->instancia;
        $conversa = $conversas->paraInstancia($instancia);

        // A conversa já seguiu em frente (outra foto chegou, você cancelou):
        // esta análise ficou obsoleta.
        if ($conversa->mensagem_id !== $mensagem->id || $conversa->anexo_path === null) {
            return;
        }

        $disk = Storage::disk('local');
        $path = $conversa->anexo_path;
        if (! $disk->exists($path)) {
            Log::warning("[whatsapp:anexo] arquivo não encontrado: {$path}");
            $conversas->transicionar($instancia, fn (WhatsappConversa $c) => $conversas->fechar($c));

            return;
        }

        $binario = (string) $disk->get($path);

        try {
            $resultado = $this->fluxo === WhatsappConversa::FLUXO_COMPROVANTE
                ? $this->analisarComprovante($binario, $mensagem, $user, $receiptParser, $destinos)
                : $this->analisarRefeicao($binario, $mensagem, $nutricaoIa);
        } catch (Throwable $e) {
            // Não relança: com retry, cada tentativa mandaria outra mensagem de erro.
            Log::error("[whatsapp:anexo] falha na análise ({$this->fluxo}): ".$e->getMessage());
            $this->encerrar($conversas, $instancia, $sender, '⚠️ Não consegui ler essa foto agora. Tente de novo ou lance pelo painel.');

            return;
        }

        if ($resultado['recusa'] !== null) {
            $this->encerrar($conversas, $instancia, $sender, $resultado['recusa']);

            return;
        }

        $conversas->transicionar($instancia, fn (WhatsappConversa $c) => $conversas->abrir(
            conversa: $c,
            estado: WhatsappConversa::AGUARDANDO_CONFIRMACAO,
            fluxo: $this->fluxo,
            mensagemId: $mensagem->id,
            payload: $resultado['payload'],
            anexoPath: $path,
            chatId: $c->chat_id,
        ));

        $sender->enviarParaMim($instancia, $this->propor($resultado['payload'], $texto));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function propor(array $payload, WhatsappPropostaTexto $texto): string
    {
        if ($this->fluxo === WhatsappConversa::FLUXO_COMPROVANTE) {
            return $texto->propostaComprovante($payload['lancamento'], $payload['destino_label'] ?? null);
        }

        return $texto->propostaRefeicao($payload['analise']);
    }

    /**
     * @return array{payload: array<string, mixed>, recusa: string|null}
     */
    private function analisarRefeicao(string $binario, WhatsappMensagem $mensagem, SaudeNutricaoAI $ia): array
    {
        $analise = $ia->analisarFoto($binario, (string) $mensagem->media_mime, $mensagem->caption);

        if (! $analise['e_comida']) {
            return ['payload' => [], 'recusa' => '🤔 Não identifiquei comida nessa foto.'];
        }

        return ['payload' => ['analise' => $analise], 'recusa' => null];
    }

    /**
     * Pelo chat só entra comprovante avulso: fatura e extrato rendem dezenas de
     * itens que precisam de revisão linha a linha, o que é trabalho de painel.
     *
     * @return array{payload: array<string, mixed>, recusa: string|null}
     */
    private function analisarComprovante(
        string $binario,
        WhatsappMensagem $mensagem,
        \App\Models\User $user,
        ReceiptParser $parser,
        FinanceDestinoResolver $destinos,
    ): array {
        $categorias = FinanceCategory::where('user_id', $user->id)
            ->where('kind', 'despesa')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])
            ->all();

        $mime = (string) $mensagem->media_mime;
        $resultado = $parser->parse($binario, $mime, WhatsappIngestService::extensaoDoMime($mime), collect($categorias));

        $itens = $resultado['items'];
        if ($resultado['document_type'] !== 'comprovante' || count($itens) !== 1) {
            $quantos = count($itens);

            return ['payload' => [], 'recusa' => "📄 Isso parece uma fatura/extrato com {$quantos} lançamentos. Manda pelo painel (Financeiro → Lançar por IA), que lá dá para revisar item a item."];
        }

        $item = $itens[0];
        if ($item['amount'] === null || $item['amount'] <= 0) {
            return ['payload' => [], 'recusa' => '🤔 Não consegui ler o valor desse comprovante.'];
        }

        $opcoes = $destinos->opcoes($user);
        if ($opcoes === []) {
            return ['payload' => [], 'recusa' => '🏦 Você ainda não tem conta nem cartão cadastrado no Financeiro.'];
        }

        $sugerido = $destinos->sugerir($opcoes, $resultado['card_last_four'], $item['payment_method'], $user);

        $categoriaNome = null;
        foreach ($categorias as $c) {
            if ($c['id'] === $item['category_id']) {
                $categoriaNome = $c['name'];
            }
        }

        return [
            'payload' => [
                'lancamento' => [
                    'description' => (string) ($item['description'] ?? 'Comprovante'),
                    'amount' => (float) $item['amount'],
                    'date' => $item['purchase_date'] ?? now(config('saude.timezone'))->toDateString(),
                    'category_id' => $item['category_id'],
                    'category_nome' => $categoriaNome,
                    'installments_total' => $item['installments_total'],
                ],
                'destino' => $sugerido['chave'] ?? null,
                'destino_label' => $sugerido['label'] ?? null,
                // Congeladas COM tipo e id: reconstruir a lista no turno seguinte
                // faria o "2" apontar para outra conta se você cadastrar uma no
                // meio — e é o tipo/id daqui que o commit usa para lançar.
                'opcoes' => $opcoes,
                'categorias' => $categorias,
            ],
            'recusa' => null,
        ];
    }

    private function encerrar(
        WhatsappConversaService $conversas,
        \App\Models\WhatsappInstancia $instancia,
        WhatsappSender $sender,
        string $mensagem,
    ): void {
        $conversas->transicionar($instancia, fn (WhatsappConversa $c) => $conversas->fechar($c));
        $sender->enviarParaMim($instancia, $mensagem);
    }

    public function failed(?Throwable $e): void
    {
        $mensagem = WhatsappMensagem::with('instancia')->find($this->mensagemId);
        if ($mensagem?->instancia === null) {
            return;
        }

        $conversas = app(WhatsappConversaService::class);
        $conversas->transicionar($mensagem->instancia, fn (WhatsappConversa $c) => $conversas->fechar($c));

        app(WhatsappSender::class)->enviarParaMim(
            $mensagem->instancia,
            '⚠️ Deu erro ao ler essa foto. Tente de novo ou lance pelo painel.',
        );
    }
}
