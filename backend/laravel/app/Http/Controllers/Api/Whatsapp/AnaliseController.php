<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappChat;
use App\Models\WhatsappSugestao;
use App\Services\Whatsapp\WhatsappAnaliseService;
use App\Services\Whatsapp\WhatsappTaskBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Análise de conversas por IA e o ciclo de vida das sugestões de tarefa
 * (aceitar -> vira tarefa no kanban; descartar).
 */
class AnaliseController extends Controller
{
    private const MAX_MENSAGENS = 200;
    private const MAX_CHARS_POR_MENSAGEM = 500;

    public function analisarChat(Request $request, WhatsappChat $chat, WhatsappAnaliseService $analise): JsonResponse
    {
        $this->authorizeChat($request, $chat);

        $data = $request->validate([
            'periodo_dias' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $dias = (int) ($data['periodo_dias'] ?? 7);

        $desdeMs = now()->subDays($dias)->getTimestampMs();
        $tz = config('whatsapp.relatorio.timezone');

        $mensagens = $chat->mensagens()
            ->where('momment', '>=', $desdeMs)
            ->orderByDesc('momment')
            ->limit(self::MAX_MENSAGENS)
            ->get()
            ->reverse()
            ->values();

        if ($mensagens->isEmpty()) {
            return response()->json(['message' => 'Sem mensagens no período para analisar.'], 422);
        }

        $conversa = [
            'chat_id' => $chat->id,
            'nome' => $chat->nomeExibicao(),
            'monitorado' => $chat->monitorado,
            'is_group' => $chat->is_group,
            'horas_sem_resposta' => null,
            'mensagens' => $mensagens->map(function ($m) use ($tz) {
                $quando = $m->momment > 0
                    ? now($tz)->setTimestamp(intdiv($m->momment, 1000))->format('d/m H:i')
                    : $m->created_at->timezone($tz)->format('d/m H:i');

                return [
                    'quando' => $quando,
                    'de' => $m->from_me ? 'Eu' : ($m->sender_name ?: 'Contato'),
                    'texto' => mb_substr(trim((string) $m->texto), 0, self::MAX_CHARS_POR_MENSAGEM),
                ];
            })->all(),
        ];

        try {
            $resultado = $analise->analisarConversa($conversa);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sugestoes = [];
        foreach ($resultado['sugestoes_tarefas'] as $s) {
            $sugestoes[] = WhatsappSugestao::create([
                'user_id' => $request->user()->id,
                'chat_id' => $chat->id,
                'origem' => 'analise',
                'titulo' => $s['titulo'],
                'descricao' => $s['descricao'],
                'prioridade' => $s['prioridade'],
                'due_date' => $s['due_date'],
                'contexto' => "Análise da conversa com {$chat->nomeExibicao()} (últimos {$dias} dias)",
            ]);
        }

        return response()->json([
            'resumo' => $resultado['resumo'],
            'pontos_de_atencao' => $resultado['pontos_de_atencao'],
            'sugestoes' => $sugestoes,
        ]);
    }

    public function sugestoes(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pendente');

        $query = WhatsappSugestao::with('chat:id,chat_name,sender_name,phone,chave', 'task:id,title,project_id')
            ->where('user_id', $request->user()->id);

        if (in_array($status, ['pendente', 'aceita', 'descartada'], true)) {
            $query->where('status', $status);
        }

        return response()->json(['sugestoes' => $query->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function aceitar(Request $request, WhatsappSugestao $sugestao, WhatsappTaskBridge $bridge): JsonResponse
    {
        $this->authorizeSugestao($request, $sugestao);

        if ($sugestao->status !== 'pendente') {
            return response()->json(['message' => 'Esta sugestão já foi tratada.'], 422);
        }

        $data = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'column_id' => ['nullable', 'integer'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'prioridade' => ['nullable', 'in:low,medium,high,urgent'],
        ]);

        $task = $bridge->criarTarefa($request->user(), [
            'titulo' => $data['titulo'] ?? $sugestao->titulo,
            'descricao' => $sugestao->descricao,
            'prioridade' => $data['prioridade'] ?? $sugestao->prioridade,
            'due_date' => $data['due_date'] ?? $sugestao->due_date?->toDateString(),
        ], $data['project_id'] ?? null, $data['column_id'] ?? null);

        $sugestao->update(['status' => 'aceita', 'task_id' => $task->id]);

        return response()->json(['sugestao' => $sugestao->fresh('task'), 'task' => $task]);
    }

    public function descartar(Request $request, WhatsappSugestao $sugestao): JsonResponse
    {
        $this->authorizeSugestao($request, $sugestao);

        if ($sugestao->status !== 'pendente') {
            return response()->json(['message' => 'Esta sugestão já foi tratada.'], 422);
        }

        $sugestao->update(['status' => 'descartada']);

        return response()->json(['sugestao' => $sugestao]);
    }

    private function authorizeChat(Request $request, WhatsappChat $chat): void
    {
        $chat->loadMissing('instancia');
        abort_unless(
            $chat->instancia !== null && (int) $chat->instancia->user_id === (int) $request->user()->id,
            403,
            'Este chat não pertence a você.',
        );
    }

    private function authorizeSugestao(Request $request, WhatsappSugestao $sugestao): void
    {
        abort_unless((int) $sugestao->user_id === (int) $request->user()->id, 403, 'Esta sugestão não pertence a você.');
    }
}
