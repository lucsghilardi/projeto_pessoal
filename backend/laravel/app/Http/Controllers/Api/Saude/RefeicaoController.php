<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeRefeicao;
use App\Services\Saude\SaudeNutricaoAI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RefeicaoController extends Controller
{
    private const DISK = 'local';

    private const TIPOS = ['cafe_da_manha', 'almoco', 'jantar', 'lanche', 'outro'];

    private const CONFIANCAS = ['alta', 'media', 'baixa'];

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

    /**
     * Estimativa da IA para preencher o formulário do painel — a partir de uma
     * descrição ("2 ovos mexidos e café com leite"), de uma foto do prato, ou
     * das duas (aí a descrição corrige o que a foto não mostra).
     *
     * Não persiste nada: quem confirma é o usuário, no store.
     */
    public function analisar(Request $request, SaudeNutricaoAI $ia): JsonResponse
    {
        $request->validate([
            'descricao' => ['nullable', 'string', 'max:1000'],
            'foto' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $descricao = trim((string) $request->input('descricao', ''));
        $foto = $request->file('foto');

        if ($descricao === '' && $foto === null) {
            return response()->json([
                'message' => 'Descreva a refeição ou anexe uma foto do prato.',
            ], 422);
        }

        try {
            $analise = $foto !== null
                ? $ia->analisarFoto(
                    (string) file_get_contents($foto->getRealPath()),
                    $foto->getMimeType() ?? 'image/jpeg',
                    $descricao !== '' ? $descricao : null,
                )
                : $ia->analisarTexto($descricao);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($analise);
    }

    /** Lançamento pelo painel (manual ou confirmando a estimativa da IA). */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        $refeicao = SaudeRefeicao::create([
            ...$data,
            ...$this->procedencia($request),
            'user_id' => $request->user()->id,
            'foto_path' => $this->guardarFoto($request, $request->file('foto')),
        ]);

        return response()->json($refeicao, 201);
    }

    /** Edição livre — inclusive para corrigir estimativas da IA. */
    public function update(Request $request, SaudeRefeicao $refeicao): JsonResponse
    {
        $this->authorizeOwnership($request, $refeicao);

        // Procedência (origem/confiança/itens) só muda quando o cliente a envia:
        // editar uma refeição vinda do WhatsApp não pode transformá-la em manual.
        $data = [...$this->validateData($request), ...$this->procedencia($request, apenasEnviados: true)];

        $foto = $request->file('foto');
        $removerFoto = $request->boolean('remover_foto');

        if ($foto !== null || $removerFoto) {
            $anterior = $refeicao->foto_path;
            $data['foto_path'] = $foto !== null ? $this->guardarFoto($request, $foto) : null;

            if ($anterior !== null) {
                Storage::disk(self::DISK)->delete($anterior);
            }
        }

        $refeicao->update($data);

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

    /** Foto original da refeição (do WhatsApp ou anexada no painel). */
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
            'foto' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'remover_foto' => ['nullable', 'boolean'],
            // Detalhamento e confiança só chegam quando o usuário confirma uma
            // estimativa que a IA fez aqui pelo painel.
            'origem' => ['nullable', Rule::in(['manual', 'painel_ia'])],
            'confianca' => ['nullable', Rule::in(self::CONFIANCAS)],
            'itens' => ['nullable', 'array', 'max:40'],
            'itens.*.nome' => ['required', 'string', 'max:120'],
            'itens.*.quantidade' => ['nullable', 'string', 'max:80'],
            'itens.*.calorias' => ['required', 'integer', 'min:0', 'max:5000'],
            'itens.*.proteinas_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
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

    /**
     * Origem, confiança e detalhamento por alimento. No update, devolve só o que
     * veio na requisição, para não apagar a procedência de refeições do WhatsApp.
     *
     * @return array<string, mixed>
     */
    private function procedencia(Request $request, bool $apenasEnviados = false): array
    {
        $itens = $request->input('itens');
        $procedencia = [
            'origem' => $request->input('origem') ?: 'manual',
            'confianca' => $request->input('confianca') ?: null,
            'itens' => is_array($itens) && $itens !== [] ? array_values($itens) : null,
        ];

        if (! $apenasEnviados) {
            return $procedencia;
        }

        return array_intersect_key(
            $procedencia,
            array_flip(array_filter(
                ['origem', 'confianca', 'itens'],
                fn (string $campo) => $request->has($campo),
            )),
        );
    }

    /** Guarda a foto no mesmo diretório privado por usuário usado pelo WhatsApp. */
    private function guardarFoto(Request $request, ?UploadedFile $foto): ?string
    {
        return $foto?->store('saude/refeicoes/'.$request->user()->id, self::DISK) ?: null;
    }

    private function authorizeOwnership(Request $request, SaudeRefeicao $refeicao): void
    {
        abort_unless((int) $refeicao->user_id === (int) $request->user()->id, 403);
    }
}
