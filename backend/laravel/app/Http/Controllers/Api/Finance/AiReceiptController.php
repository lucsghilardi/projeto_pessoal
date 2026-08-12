<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\CreditCard;
use App\Models\CreditCardTransaction;
use App\Models\FinanceCategory;
use App\Models\Payable;
use App\Services\Finance\ReceiptEntryService;
use App\Services\ReceiptAI\ReceiptParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiReceiptController extends Controller
{
    private const DISK = 'local';

    /**
     * Recebe a foto (comprovante) ou PDF (fatura), guarda em disco privado e pede a leitura à IA.
     */
    public function parse(Request $request, ReceiptParser $parser): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:jpg,jpeg,png,webp,gif,pdf,csv,txt,ofx,qfx', 'max:20480'],
        ]);

        $userId = $request->user()->id;
        $file = $request->file('file');

        $receiptPath = $file->store("receipts/{$userId}", self::DISK);

        $categories = FinanceCategory::query()
            ->where('user_id', $userId)
            ->where('kind', 'despesa')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]);

        try {
            $result = $parser->parse(
                file_get_contents($file->getRealPath()),
                $file->getMimeType() ?? 'application/octet-stream',
                $file->getClientOriginalExtension(),
                $categories,
            );
        } catch (RuntimeException $e) {
            Storage::disk(self::DISK)->delete($receiptPath);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $documentType = $result['document_type'];
        $items = $result['items'];

        // Sugere o cartão da fatura casando os 4 últimos dígitos com um cartão cadastrado.
        $suggestedCardId = null;
        if (! empty($result['card_last_four'])) {
            $suggestedCardId = CreditCard::query()
                ->where('user_id', $userId)
                ->where('last_four', $result['card_last_four'])
                ->value('id');
        }

        // Marca duplicados já lançados. Se o cartão foi auto-detectado, já checa contra ele;
        // senão fica como falso e o front refaz a checagem contra o cartão/conta escolhido.
        $itemsForFlag = array_map(fn ($item) => [
            'description' => $item['description'],
            'amount' => $item['amount'],
            'date' => $item['purchase_date'],
        ], $items);

        $flags = $suggestedCardId
            ? $this->flagDuplicates($itemsForFlag, $this->cardCountResolver($suggestedCardId))
            : array_fill(0, count($items), false);

        foreach ($items as $i => &$item) {
            $item['duplicate'] = $flags[$i] ?? false;
        }
        unset($item);

        $firstMethod = $items[0]['payment_method'] ?? 'desconhecido';

        $destination = match ($documentType) {
            'fatura' => 'cartao',
            'extrato' => 'conta',
            default => $firstMethod === 'credito' ? 'cartao' : 'conta',
        };

        return response()->json([
            'receipt_path' => $receiptPath,
            'document_type' => $documentType,
            'card_last_four' => $result['card_last_four'],
            'suggested_card_id' => $suggestedCardId,
            'items' => $items,
            'suggestion' => [
                'destination' => $destination,
            ],
        ]);
    }

    /**
     * Persiste UM lançamento revisado (comprovante avulso) no destino escolhido.
     */
    public function confirm(Request $request, ReceiptEntryService $entries): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'destination' => ['required', Rule::in(['cartao', 'conta'])],
            'receipt_path' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date_format:Y-m-d'],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $userId),
            ],
            'credit_card_id' => [
                'required_if:destination,cartao', 'nullable', 'integer',
                Rule::exists('credit_cards', 'id')->where('user_id', $userId),
            ],
            'installments_total' => ['nullable', 'integer', 'min:2', 'max:360'],
            'reference_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'bank_account_id' => [
                'required_if:destination,conta', 'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $userId),
            ],
        ]);

        $receiptPath = $this->assertReceiptOwnership($userId, $data['receipt_path']);
        if ($receiptPath === null) {
            return response()->json(['message' => 'Comprovante inválido. Reenvie o arquivo.'], 422);
        }

        $fingerprint = ReceiptEntryService::fingerprint($data['description'], $data['amount'], $data['date']);

        if ($entries->isDuplicate($userId, $data, $fingerprint)) {
            return response()->json([
                'message' => 'Lançamento idêntico já existe (mesma descrição, valor e data).',
            ], 422);
        }

        $created = $entries->lancar($userId, $data, $receiptPath)['quantidade'];

        return response()->json(['message' => 'Lançamento criado.', 'created' => $created], 201);
    }

    /**
     * Reavalia quais itens já existem no destino escolhido (cartão ou conta).
     * Usado pela tela quando o usuário seleciona/troca o cartão ou a conta de destino.
     */
    public function checkDuplicates(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'destination' => ['required', Rule::in(['cartao', 'conta'])],
            'credit_card_id' => [
                'required_if:destination,cartao', 'nullable', 'integer',
                Rule::exists('credit_cards', 'id')->where('user_id', $userId),
            ],
            'bank_account_id' => [
                'required_if:destination,conta', 'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $userId),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['present', 'nullable', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric'],
            'items.*.date' => ['required', 'date_format:Y-m-d'],
        ]);

        $resolver = $data['destination'] === 'cartao'
            ? $this->cardCountResolver((int) $data['credit_card_id'])
            : $this->accountCountResolver($userId);

        return response()->json([
            'duplicates' => $this->flagDuplicates($data['items'], $resolver),
        ]);
    }

    /**
     * Persiste VÁRIOS lançamentos (fatura ou extrato) no destino escolhido, pulando os já lançados.
     * Conta (extrato): cria como pago SEM debitar o saldo, pois o extrato já reflete o saldo atual.
     */
    public function confirmBatch(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'destination' => ['required', Rule::in(['cartao', 'conta'])],
            'receipt_path' => ['required', 'string', 'max:255'],
            'credit_card_id' => [
                'required_if:destination,cartao', 'nullable', 'integer',
                Rule::exists('credit_cards', 'id')->where('user_id', $userId),
            ],
            'bank_account_id' => [
                'required_if:destination,conta', 'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('user_id', $userId),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            // Aceita negativos: estornos/créditos de fatura entram como amount negativo.
            'items.*.amount' => ['required', 'numeric'],
            'items.*.date' => ['required', 'date_format:Y-m-d'],
            'items.*.category_id' => [
                'nullable', 'integer',
                Rule::exists('finance_categories', 'id')->where('user_id', $userId),
            ],
        ]);

        $receiptPath = $this->assertReceiptOwnership($userId, $data['receipt_path']);
        if ($receiptPath === null) {
            return response()->json(['message' => 'Arquivo inválido. Reenvie o arquivo.'], 422);
        }

        [$created, $skipped] = $data['destination'] === 'cartao'
            ? $this->importToCard($userId, $data, $receiptPath)
            : $this->importToAccount($userId, $data, $receiptPath);

        $where = $data['destination'] === 'cartao' ? 'Fatura' : 'Extrato';

        return response()->json([
            'message' => "{$where} importado: {$created} novo(s), {$skipped} já existente(s).",
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:int,1:int} [created, skipped]
     */
    private function importToCard(int $userId, array $data, string $receiptPath): array
    {
        $card = CreditCard::query()->where('user_id', $userId)->findOrFail($data['credit_card_id']);
        $created = 0;
        $skipped = 0;

        // Quantos de cada impressão digital já existem (consciente de repetição).
        $remaining = ($this->cardCountResolver($card->id))($this->fingerprintsOf($data['items']));

        DB::transaction(function () use ($card, $userId, $data, $receiptPath, &$created, &$skipped, &$remaining) {
            foreach ($data['items'] as $item) {
                $fingerprint = ReceiptEntryService::fingerprint($item['description'], $item['amount'], $item['date']);

                if (($remaining[$fingerprint] ?? 0) > 0) {
                    $remaining[$fingerprint]--;
                    $skipped++;

                    continue;
                }

                $purchase = Carbon::parse($item['date'])->startOfDay();
                $invoice = $card->invoiceForReferenceMonth($card->resolveInvoiceWindow($purchase)['reference_month']);

                CreditCardTransaction::create([
                    'user_id' => $userId,
                    'credit_card_id' => $card->id,
                    'credit_card_invoice_id' => $invoice->id,
                    'category_id' => $item['category_id'] ?? null,
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'purchase_date' => $purchase->toDateString(),
                    'receipt_path' => $receiptPath,
                    'import_fingerprint' => $fingerprint,
                ]);

                $created++;
            }
        });

        return [$created, $skipped];
    }

    /**
     * Importa um extrato para uma conta: cria lançamentos pagos SEM mexer no saldo.
     *
     * @param  array<string, mixed>  $data
     * @return array{0:int,1:int} [created, skipped]
     */
    private function importToAccount(int $userId, array $data, string $receiptPath): array
    {
        $accountId = (int) $data['bank_account_id'];
        $created = 0;
        $skipped = 0;

        $remaining = ($this->accountCountResolver($userId))($this->fingerprintsOf($data['items']));

        DB::transaction(function () use ($userId, $accountId, $data, $receiptPath, &$created, &$skipped, &$remaining) {
            foreach ($data['items'] as $item) {
                $fingerprint = ReceiptEntryService::fingerprint($item['description'], $item['amount'], $item['date']);

                if (($remaining[$fingerprint] ?? 0) > 0) {
                    $remaining[$fingerprint]--;
                    $skipped++;

                    continue;
                }

                $date = Carbon::parse($item['date'])->startOfDay()->toDateString();

                Payable::create([
                    'user_id' => $userId,
                    'category_id' => $item['category_id'] ?? null,
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'due_date' => $date,
                    'kind' => 'avulsa',
                    'is_paid' => true,
                    'paid_at' => $date,
                    'bank_account_id' => $accountId,
                    'receipt_path' => $receiptPath,
                    'import_fingerprint' => $fingerprint,
                ]);

                $created++;
            }
        });

        return [$created, $skipped];
    }

    /**
     * Marca como duplicado os N primeiros itens de cada impressão digital que já existem no destino
     * (consciente de repetição: itens idênticos além do que já está salvo continuam como novos).
     *
     * @param  list<array{description?:string|null,amount:mixed,date:string}>  $items
     * @param  \Closure(list<string>): array<string,int>  $countResolver
     * @return list<bool>
     */
    private function flagDuplicates(array $items, \Closure $countResolver): array
    {
        $fingerprints = array_map(
            fn ($item) => ReceiptEntryService::fingerprint($item['description'] ?? null, $item['amount'], $item['date']),
            $items
        );

        $remaining = $countResolver(array_values(array_unique($fingerprints)));

        $flags = [];
        foreach ($fingerprints as $fingerprint) {
            $isDuplicate = ($remaining[$fingerprint] ?? 0) > 0;
            if ($isDuplicate) {
                $remaining[$fingerprint]--;
            }
            $flags[] = $isDuplicate;
        }

        return $flags;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private function fingerprintsOf(array $items): array
    {
        return array_values(array_unique(array_map(
            fn ($item) => ReceiptEntryService::fingerprint($item['description'] ?? null, $item['amount'], $item['date']),
            $items
        )));
    }

    /**
     * Resolve quantas transações de cada impressão digital já existem no cartão.
     *
     * @return \Closure(list<string>): array<string,int>
     */
    private function cardCountResolver(int $cardId): \Closure
    {
        return fn (array $fingerprints) => empty($fingerprints) ? [] : CreditCardTransaction::query()
            ->where('credit_card_id', $cardId)
            ->whereIn('import_fingerprint', $fingerprints)
            ->selectRaw('import_fingerprint, count(*) as total')
            ->groupBy('import_fingerprint')
            ->pluck('total', 'import_fingerprint')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Resolve quantos lançamentos de cada impressão digital já existem nas contas do usuário.
     *
     * @return \Closure(list<string>): array<string,int>
     */
    private function accountCountResolver(int $userId): \Closure
    {
        return fn (array $fingerprints) => empty($fingerprints) ? [] : Payable::query()
            ->where('user_id', $userId)
            ->whereIn('import_fingerprint', $fingerprints)
            ->selectRaw('import_fingerprint, count(*) as total')
            ->groupBy('import_fingerprint')
            ->pluck('total', 'import_fingerprint')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Streama a imagem/PDF do comprovante anexado a um lançamento, checando posse.
     */
    public function download(Request $request, string $type, int $id): StreamedResponse
    {
        $userId = $request->user()->id;

        $entry = match ($type) {
            'payable' => Payable::query()->where('user_id', $userId)->findOrFail($id),
            'cc' => CreditCardTransaction::query()->where('user_id', $userId)->findOrFail($id),
            default => abort(404),
        };

        abort_if(empty($entry->receipt_path) || ! Storage::disk(self::DISK)->exists($entry->receipt_path), 404);

        return Storage::disk(self::DISK)->response($entry->receipt_path);
    }

    /**
     * Confirma que o arquivo pertence ao usuário e existe; devolve o path validado ou null.
     */
    private function assertReceiptOwnership(int $userId, string $receiptPath): ?string
    {
        if (! Str::startsWith($receiptPath, "receipts/{$userId}/") || ! Storage::disk(self::DISK)->exists($receiptPath)) {
            return null;
        }

        return $receiptPath;
    }
}
