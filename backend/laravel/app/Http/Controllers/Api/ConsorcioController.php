<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consorcio;
use App\Models\Payable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConsorcioController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Agrega as parcelas (contas a pagar) pagas/total por consórcio de uma vez (evita N+1).
        $paidByConsorcio = Payable::query()
            ->where('user_id', $userId)
            ->whereNotNull('consorcio_id')
            ->where('is_paid', true)
            ->selectRaw('consorcio_id, count(*) as qtd, coalesce(sum(amount), 0) as total')
            ->groupBy('consorcio_id')
            ->get()
            ->keyBy('consorcio_id');

        $consorcios = Consorcio::query()
            ->where('user_id', $userId)
            ->orderBy('nome')
            ->get()
            ->map(function (Consorcio $consorcio) use ($paidByConsorcio) {
                $paid = $paidByConsorcio->get($consorcio->id);

                return array_merge($consorcio->toArray(), [
                    'parcelas_pagas_count' => (int) ($paid->qtd ?? 0),
                    'total_pago' => round((float) ($paid->total ?? 0), 2),
                ]);
            });

        return response()->json(['consorcios' => $consorcios]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $consorcio = Consorcio::create([
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return response()->json($consorcio, 201);
    }

    public function update(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        $data = $this->validateData($request, $consorcio);

        $consorcio->update($data);

        return response()->json($consorcio->fresh());
    }

    public function destroy(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        if ($consorcio->proposta_path) {
            Storage::disk(self::DISK)->delete($consorcio->proposta_path);
        }

        $consorcio->delete();

        return response()->json(['message' => 'Consórcio removido com sucesso.']);
    }

    /**
     * Envia (ou substitui) o arquivo da proposta — guardado em disco privado.
     */
    public function uploadProposta(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        $request->validate([
            'file' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png,webp', 'max:20480'],
        ]);

        if ($consorcio->proposta_path) {
            Storage::disk(self::DISK)->delete($consorcio->proposta_path);
        }

        $path = $request->file('file')->store("consorcios/{$consorcio->user_id}", self::DISK);

        $consorcio->update(['proposta_path' => $path]);

        return response()->json($consorcio->fresh());
    }

    public function downloadProposta(Request $request, Consorcio $consorcio): StreamedResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        abort_if(
            empty($consorcio->proposta_path) || ! Storage::disk(self::DISK)->exists($consorcio->proposta_path),
            404,
        );

        return Storage::disk(self::DISK)->response($consorcio->proposta_path);
    }

    public function deleteProposta(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        if ($consorcio->proposta_path) {
            Storage::disk(self::DISK)->delete($consorcio->proposta_path);
            $consorcio->update(['proposta_path' => null]);
        }

        return response()->json($consorcio->fresh());
    }

    /**
     * Aplica um reajuste anual: sobe a mensalidade padrão e as parcelas em aberto
     * (não pagas) pelo percentual informado. Parcelas já pagas não são alteradas.
     */
    public function aplicarReajuste(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeOwnership($request, $consorcio);

        $data = $request->validate([
            'percentual' => ['required', 'numeric', 'min:-50', 'max:100'],
        ]);

        $factor = 1 + ((float) $data['percentual'] / 100);

        DB::transaction(function () use ($consorcio, $factor) {
            if ($consorcio->valor_mensalidade !== null) {
                $consorcio->update([
                    'valor_mensalidade' => round((float) $consorcio->valor_mensalidade * $factor, 2),
                ]);
            }

            // Reajusta as parcelas (contas a pagar) em aberto deste consórcio.
            $consorcio->parcelas()
                ->where('is_paid', false)
                ->get()
                ->each(function (Payable $parcela) use ($factor) {
                    $parcela->update(['amount' => round((float) $parcela->amount * $factor, 2)]);
                });
        });

        $parcelas = $consorcio->parcelas()->with(['category:id,name,color,kind', 'bankAccount:id,name'])->get();

        return response()->json([
            'consorcio' => $consorcio->fresh(),
            'parcelas' => $parcelas,
        ]);
    }

    private function validateData(Request $request, ?Consorcio $ignore = null): array
    {
        return $request->validate([
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('consorcios', 'nome')
                    ->where('user_id', $request->user()->id)
                    ->ignore($ignore?->id),
            ],
            'administradora' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(['contemplado', 'novo'])],
            'grupo' => ['nullable', 'string', 'max:255'],
            'cota' => ['nullable', 'string', 'max:255'],
            'valor_credito' => ['nullable', 'numeric', 'min:0'],
            'valor_mensalidade' => ['nullable', 'numeric', 'min:0'],
            'reducao_pct' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'prazo_meses' => ['nullable', 'integer', 'min:1', 'max:600'],
            'parcelas_pagas' => ['nullable', 'integer', 'min:0', 'max:600'],
            'valor_pago_inicial' => ['nullable', 'numeric', 'min:0'],
            'dia_vencimento' => ['nullable', 'integer', 'min:1', 'max:31'],
            'data_contemplacao' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['ativo', 'quitado', 'cancelado'])],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeOwnership(Request $request, Consorcio $consorcio): void
    {
        abort_unless((int) $consorcio->user_id === (int) $request->user()->id, 403);
    }
}
