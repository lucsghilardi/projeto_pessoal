<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consorcio;
use App\Models\FinanceCategory;
use App\Models\Payable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parcelas do carnê de um consórcio. São armazenadas como contas a pagar (Payable)
 * vinculadas pelo consorcio_id, então aparecem em "Contas a pagar" e usam a mesma baixa
 * (débito de saldo). Pagar/estornar/editar/excluir parcelas individuais é feito pelos
 * endpoints de payables; aqui ficam só a listagem, a criação avulsa e a geração do carnê.
 */
class ConsorcioParcelaController extends Controller
{
    public function index(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeConsorcio($request, $consorcio);

        return response()->json(['parcelas' => $this->listar($consorcio)]);
    }

    public function store(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeConsorcio($request, $consorcio);

        $data = $request->validate([
            'numero' => ['nullable', 'integer', 'min:1', 'max:600'],
            'vencimento' => ['required', 'date'],
            'valor' => ['required', 'numeric', 'gt:0'],
        ]);

        $categoryId = $this->consorcioCategoryId($consorcio->user_id);

        $consorcio->parcelas()->create([
            'user_id' => $consorcio->user_id,
            'category_id' => $categoryId,
            'description' => $this->descricao($consorcio, $data['numero'] ?? null),
            'amount' => $data['valor'],
            'due_date' => $data['vencimento'],
            'kind' => 'parcelada',
            'installment_number' => $data['numero'] ?? null,
            'installments_total' => $consorcio->prazo_meses,
        ]);

        return response()->json(['parcelas' => $this->listar($consorcio)], 201);
    }

    /**
     * Gera o carnê: cria N parcelas mensais (somando 1 mês a cada uma).
     */
    public function generate(Request $request, Consorcio $consorcio): JsonResponse
    {
        $this->authorizeConsorcio($request, $consorcio);

        $data = $request->validate([
            'quantidade' => ['required', 'integer', 'min:1', 'max:600'],
            'valor' => ['required', 'numeric', 'gt:0'],
            'primeiro_vencimento' => ['required', 'date'],
            'numero_inicial' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $categoryId = $this->consorcioCategoryId($consorcio->user_id);
        $base = Carbon::parse($data['primeiro_vencimento'])->startOfDay();
        $dia = $base->day;
        $numero = $data['numero_inicial'] ?? 1;
        $group = (string) Str::uuid();
        $criadas = 0;

        DB::transaction(function () use ($consorcio, $data, $base, $dia, $categoryId, $group, &$numero, &$criadas) {
            for ($i = 0; $i < $data['quantidade']; $i++) {
                $venc = $base->copy()->addMonthsNoOverflow($i);
                $venc = $venc->day(min($dia, $venc->daysInMonth));

                $consorcio->parcelas()->create([
                    'user_id' => $consorcio->user_id,
                    'category_id' => $categoryId,
                    'description' => $this->descricao($consorcio, $numero),
                    'amount' => $data['valor'],
                    'due_date' => $venc->toDateString(),
                    'kind' => 'parcelada',
                    'installment_number' => $numero,
                    'installments_total' => $consorcio->prazo_meses,
                    'group_id' => $group,
                ]);

                $numero++;
                $criadas++;
            }
        });

        return response()->json(['parcelas' => $this->listar($consorcio), 'criadas' => $criadas], 201);
    }

    private function listar(Consorcio $consorcio)
    {
        return $consorcio->parcelas()
            ->with(['category:id,name,color,kind', 'bankAccount:id,name'])
            ->get();
    }

    private function descricao(Consorcio $consorcio, ?int $numero): string
    {
        $sufixo = $numero
            ? ' - parcela ' . $numero . ($consorcio->prazo_meses ? '/' . $consorcio->prazo_meses : '')
            : '';

        return "{$consorcio->nome}{$sufixo}";
    }

    /** Categoria "Consórcio" (despesa) do usuário, criada sob demanda. */
    private function consorcioCategoryId(int $userId): int
    {
        return FinanceCategory::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Consórcio', 'kind' => 'despesa'],
            ['color' => '#7c3aed'],
        )->id;
    }

    private function authorizeConsorcio(Request $request, Consorcio $consorcio): void
    {
        abort_unless((int) $consorcio->user_id === (int) $request->user()->id, 403);
    }
}
