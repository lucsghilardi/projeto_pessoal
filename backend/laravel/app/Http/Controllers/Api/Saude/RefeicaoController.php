<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeRefeicao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefeicaoController extends Controller
{
    private const DISK = 'local';

    private const TIPOS = ['cafe_da_manha', 'almoco', 'jantar', 'lanche', 'outro'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $refeicoes = SaudeRefeicao::query()
            ->where('user_id', $request->user()->id)
            ->when($filters['de'] ?? null, fn ($q, $de) => $q->where('data', '>=', $de))
            ->when($filters['ate'] ?? null, fn ($q, $ate) => $q->where('data', '<=', $ate))
            ->orderBy('data')
            ->orderBy('horario')
            ->get();

        return response()->json($refeicoes);
    }

    /** Lançamento manual (a IA lança as do WhatsApp). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $refeicao = SaudeRefeicao::create([
            ...$data,
            'user_id' => $request->user()->id,
            'origem' => 'manual',
        ]);

        return response()->json($refeicao, 201);
    }

    /** Edição livre — inclusive para corrigir estimativas da IA. */
    public function update(Request $request, SaudeRefeicao $refeicao): JsonResponse
    {
        $this->authorizeOwnership($request, $refeicao);

        $refeicao->update($this->validateData($request));

        return response()->json($refeicao->fresh());
    }

    public function destroy(Request $request, SaudeRefeicao $refeicao): JsonResponse
    {
        $this->authorizeOwnership($request, $refeicao);

        if ($refeicao->foto_path !== null) {
            Storage::disk(self::DISK)->delete($refeicao->foto_path);
        }
        $refeicao->delete();

        return response()->json(['message' => 'Refeição removida com sucesso.']);
    }

    /** Foto original da refeição (quando veio pelo WhatsApp). */
    public function foto(Request $request, SaudeRefeicao $refeicao): StreamedResponse
    {
        $this->authorizeOwnership($request, $refeicao);

        $path = (string) $refeicao->foto_path;
        $prefixo = 'saude/refeicoes/'.$request->user()->id.'/';

        abort_unless(
            $path !== ''
                && Str::startsWith($path, $prefixo)
                && Storage::disk(self::DISK)->exists($path),
            404,
        );

        return Storage::disk(self::DISK)->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'data' => ['required', 'date_format:Y-m-d'],
            'horario' => ['required', 'date_format:H:i'],
            'nome' => ['required', 'string', 'max:120'],
            'tipo' => ['nullable', Rule::in(self::TIPOS)],
            'calorias' => ['required', 'integer', 'min:0', 'max:5000'],
            'proteinas_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'carboidratos_g' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'gorduras_g' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'data' => $data['data'],
            'horario' => $data['horario'],
            'nome' => $data['nome'],
            'tipo' => $data['tipo'] ?? null,
            'calorias' => $data['calorias'],
            'proteinas_g' => $data['proteinas_g'] ?? 0,
            'carboidratos_g' => $data['carboidratos_g'] ?? null,
            'gorduras_g' => $data['gorduras_g'] ?? null,
            'observacao' => $data['observacao'] ?? null,
        ];
    }

    private function authorizeOwnership(Request $request, SaudeRefeicao $refeicao): void
    {
        abort_unless((int) $refeicao->user_id === (int) $request->user()->id, 403);
    }
}
