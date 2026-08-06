<?php

namespace App\Http\Controllers\Api\Saude;

use App\Http\Controllers\Controller;
use App\Models\SaudeSuplemento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuplementoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suplementos = SaudeSuplemento::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('ativo'), fn ($q) => $q->where('ativo', true))
            ->orderBy('horario')
            ->orderBy('posicao')
            ->get();

        return response()->json($suplementos);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $data = $this->validateData($request);

        $suplemento = SaudeSuplemento::create([
            'user_id' => $userId,
            'nome' => $data['nome'],
            'marca' => $data['marca'] ?? null,
            'dose' => $data['dose'] ?? null,
            'horario' => $data['horario'],
            'instrucao' => $data['instrucao'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'ativo' => $data['ativo'] ?? true,
            'posicao' => $data['posicao']
                ?? ((int) SaudeSuplemento::where('user_id', $userId)->max('posicao') + 1),
        ]);

        return response()->json($suplemento, 201);
    }

    public function update(Request $request, SaudeSuplemento $suplemento): JsonResponse
    {
        $this->authorizeOwnership($request, $suplemento);

        $data = $this->validateData($request);

        $suplemento->update([
            'nome' => $data['nome'],
            'marca' => $data['marca'] ?? null,
            'dose' => $data['dose'] ?? null,
            'horario' => $data['horario'],
            'instrucao' => $data['instrucao'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
            'ativo' => $data['ativo'] ?? $suplemento->ativo,
            'posicao' => $data['posicao'] ?? $suplemento->posicao,
        ]);

        return response()->json($suplemento->fresh());
    }

    public function destroy(Request $request, SaudeSuplemento $suplemento): JsonResponse
    {
        $this->authorizeOwnership($request, $suplemento);

        $suplemento->delete();

        return response()->json(['message' => 'Suplemento removido com sucesso.']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'marca' => ['nullable', 'string', 'max:120'],
            'dose' => ['nullable', 'string', 'max:60'],
            'horario' => ['required', 'date_format:H:i'],
            'instrucao' => ['nullable', 'string', 'max:160'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['boolean'],
            'posicao' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function authorizeOwnership(Request $request, SaudeSuplemento $suplemento): void
    {
        abort_unless((int) $suplemento->user_id === (int) $request->user()->id, 403);
    }
}
