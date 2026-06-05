<?php

namespace App\Services\ReceiptAI;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envia um comprovante (imagem), uma fatura (PDF) ou um extrato/fatura em texto
 * (CSV/OFX) para o Claude e devolve os lançamentos estruturados.
 */
class ReceiptParser
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const TEXT_LIMIT = 60000;

    /**
     * @param  Collection<int, array{id:int,name:string}>  $categories  categorias de despesa do usuário
     * @return array{
     *     document_type: string,
     *     card_last_four: string|null,
     *     items: list<array{
     *         amount: float|null,
     *         purchase_date: string|null,
     *         description: string|null,
     *         payment_method: string,
     *         installments_total: int|null,
     *         category_id: int|null,
     *         confidence: string
     *     }>
     * }
     */
    public function parse(string $binary, string $mime, string $extension, Collection $categories): array
    {
        $key = config('services.anthropic.key');

        if (empty($key)) {
            throw new RuntimeException('A leitura por IA não está configurada. Defina ANTHROPIC_API_KEY no .env do backend.');
        }

        $allowedIds = $categories->pluck('id')->all();
        $categoryList = $categories
            ->map(fn ($c) => "{$c['id']}: {$c['name']}")
            ->implode("\n");

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])->timeout(120)->post(self::ENDPOINT, [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 4096,
                'tools' => [$this->extractionTool($allowedIds)],
                'tool_choice' => ['type' => 'tool', 'name' => 'registrar_extracao'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ...$this->sourceBlocks($binary, $mime, $extension),
                        [
                            'type' => 'text',
                            'text' => $this->prompt($categoryList),
                        ],
                    ],
                ]],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Não foi possível contatar o serviço de IA. Tente novamente.', 0, $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? 'erro desconhecido';
            throw new RuntimeException("A IA recusou a leitura do arquivo ({$detail}).");
        }

        $payload = $this->extractToolPayload($response->json());

        return $this->normalize($payload, $allowedIds);
    }

    /**
     * Monta o(s) bloco(s) de conteúdo conforme o tipo do arquivo: imagem, PDF ou texto (CSV/OFX).
     *
     * @return list<array<string, mixed>>
     */
    private function sourceBlocks(string $binary, string $mime, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'pdf' || $mime === 'application/pdf') {
            return [[
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($binary)],
            ]];
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) || str_starts_with($mime, 'image/')) {
            return [[
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $this->normalizeImageMime($mime), 'data' => base64_encode($binary)],
            ]];
        }

        // CSV / OFX / QFX / TXT — interpretado como texto.
        $text = mb_convert_encoding($binary, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (mb_strlen($text) > self::TEXT_LIMIT) {
            $text = mb_substr($text, 0, self::TEXT_LIMIT);
        }

        return [[
            'type' => 'text',
            'text' => "Conteúdo do arquivo (extrato/fatura em CSV ou OFX) a interpretar:\n\n```\n{$text}\n```",
        ]];
    }

    private function prompt(string $categoryList): string
    {
        $today = now()->toDateString();

        return <<<TXT
Você recebe UM destes (brasileiros): imagem de comprovante, PDF de fatura de cartão, ou texto de extrato/fatura (CSV/OFX).
Identifique o tipo e extraia os lançamentos chamando a ferramenta registrar_extracao.

document_type:
- "comprovante": um único pagamento (pix, débito ou crédito). Retorne exatamente 1 item.
- "fatura": fatura de cartão de crédito com várias compras. Um item por compra. Preencha card_last_four (4 últimos dígitos) se houver.
- "extrato": extrato de conta bancária (CSV/OFX/texto) com vários movimentos.

REGRA IMPORTANTE — inclua apenas DESPESAS (saídas de dinheiro):
- Em "fatura": apenas compras/serviços. NÃO inclua pagamento da fatura anterior, créditos, estornos nem o total.
- Em "extrato": apenas débitos/saídas. NÃO inclua entradas/créditos (salário, transferências recebidas, rendimentos, estornos). No OFX, TRNAMT negativo é saída (despesa).
- Sempre use amount POSITIVO (valor absoluto da despesa).

Em cada item:
- amount: valor positivo, número com ponto decimal, sem símbolo de moeda. Na fatura, use o valor cobrado NESTA fatura.
- purchase_date: data YYYY-MM-DD. Se faltar o ano, use o ano do vencimento/movimento. Se não houver data, use {$today}.
- description: estabelecimento/descrição.
- payment_method: "credito", "debito", "pix" ou "desconhecido". Em fatura use "credito"; em extrato use "debito".
- installments_total: total de parcelas para comprovante parcelado no crédito; senão null.
- category_id: id MAIS adequado da lista abaixo, ou null.
- confidence: "alta", "media" ou "baixa".

Categorias disponíveis (id: nome):
{$categoryList}
TXT;
    }

    /**
     * @param  array<int>  $allowedIds
     * @return array<string, mixed>
     */
    private function extractionTool(array $allowedIds): array
    {
        $itemSchema = [
            'type' => 'object',
            'properties' => [
                'amount' => ['type' => ['number', 'null']],
                'purchase_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                'description' => ['type' => ['string', 'null']],
                'payment_method' => ['type' => 'string', 'enum' => ['credito', 'debito', 'pix', 'desconhecido']],
                'installments_total' => ['type' => ['integer', 'null']],
                'category_id' => ['type' => ['integer', 'null'], 'enum' => [...$allowedIds, null]],
                'confidence' => ['type' => 'string', 'enum' => ['alta', 'media', 'baixa']],
            ],
            'required' => ['amount', 'purchase_date', 'description', 'payment_method', 'confidence'],
        ];

        return [
            'name' => 'registrar_extracao',
            'description' => 'Registra os lançamentos de despesa extraídos de um comprovante, fatura ou extrato.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'document_type' => ['type' => 'string', 'enum' => ['comprovante', 'fatura', 'extrato']],
                    'card_last_four' => ['type' => ['string', 'null']],
                    'items' => ['type' => 'array', 'items' => $itemSchema],
                ],
                'required' => ['document_type', 'items'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function extractToolPayload(?array $body): array
    {
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new RuntimeException('A IA não retornou os dados do arquivo. Tente um arquivo mais nítido.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int>  $allowedIds
     * @return array{document_type:string,card_last_four:string|null,items:list<array<string,mixed>>}
     */
    private function normalize(array $payload, array $allowedIds): array
    {
        $documentType = in_array($payload['document_type'] ?? null, ['comprovante', 'fatura', 'extrato'], true)
            ? $payload['document_type']
            : 'comprovante';

        $items = collect($payload['items'] ?? [])
            ->map(fn ($item) => $this->normalizeItem(is_array($item) ? $item : [], $allowedIds))
            ->filter(fn ($item) => $item['amount'] !== null && $item['amount'] > 0)
            ->values()
            ->all();

        if (empty($items)) {
            throw new RuntimeException('Nenhum lançamento de despesa foi identificado no arquivo. Tente um arquivo mais nítido.');
        }

        $lastFour = $payload['card_last_four'] ?? null;
        if (is_string($lastFour)) {
            $lastFour = preg_replace('/\D/', '', $lastFour);
            $lastFour = $lastFour !== '' ? substr($lastFour, -4) : null;
        } else {
            $lastFour = null;
        }

        return [
            'document_type' => $documentType,
            'card_last_four' => $lastFour,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int>  $allowedIds
     * @return array{amount:float|null,purchase_date:string|null,description:string|null,payment_method:string,installments_total:int|null,category_id:int|null,confidence:string}
     */
    private function normalizeItem(array $item, array $allowedIds): array
    {
        $categoryId = $item['category_id'] ?? null;
        if ($categoryId !== null && ! in_array((int) $categoryId, $allowedIds, true)) {
            $categoryId = null;
        }

        $installments = $item['installments_total'] ?? null;
        $installments = ($installments !== null && (int) $installments >= 2) ? (int) $installments : null;

        $method = $item['payment_method'] ?? 'desconhecido';
        if (! in_array($method, ['credito', 'debito', 'pix', 'desconhecido'], true)) {
            $method = 'desconhecido';
        }

        return [
            'amount' => isset($item['amount']) ? round((float) abs((float) $item['amount']), 2) : null,
            'purchase_date' => $item['purchase_date'] ?? null,
            'description' => $item['description'] ?? null,
            'payment_method' => $method,
            'installments_total' => $installments,
            'category_id' => $categoryId !== null ? (int) $categoryId : null,
            'confidence' => $item['confidence'] ?? 'baixa',
        ];
    }

    private function normalizeImageMime(string $mime): string
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mime
            : 'image/jpeg';
    }
}
