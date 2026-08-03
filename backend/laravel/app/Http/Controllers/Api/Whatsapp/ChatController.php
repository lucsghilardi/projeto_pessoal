<?php

namespace App\Http\Controllers\Api\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversas gravadas (somente leitura) + monitoramento + score de atenção.
 */
class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        if ($instancia === null) {
            return response()->json(['chats' => []]);
        }

        $query = WhatsappChat::where('instancia_id', $instancia->id);

        if ($request->boolean('monitorados')) {
            $query->where('monitorado', true);
        }
        if (! $request->boolean('arquivados')) {
            $query->where('arquivado', false);
        }
        if (($busca = trim((string) $request->query('busca', ''))) !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('chat_name', 'ilike', "%{$busca}%")
                    ->orWhere('sender_name', 'ilike', "%{$busca}%")
                    ->orWhere('phone', 'like', '%'.preg_replace('/\D+/', '', $busca).'%');
            });
        }

        $chats = $query->orderByDesc('last_message_at')->limit(300)->get();

        return response()->json(['chats' => $chats]);
    }

    public function mensagens(Request $request, WhatsappChat $chat): JsonResponse
    {
        $this->authorizeOwnership($request, $chat);

        $query = $chat->mensagens()->orderByDesc('momment')->orderByDesc('id');

        // Paginação por cursor: passe o menor momment já carregado para buscar
        // mensagens mais antigas.
        if (($before = (int) $request->query('before_momment', 0)) > 0) {
            $query->where('momment', '<', $before);
        }

        $mensagens = $query->limit(100)->get()->reverse()->values();

        // Abrir a conversa zera o contador de não lidas do painel.
        if ($chat->unread_count > 0 && $before === 0) {
            $chat->update(['unread_count' => 0]);
        }

        return response()->json(['chat' => $chat, 'mensagens' => $mensagens]);
    }

    public function monitorar(Request $request, WhatsappChat $chat): JsonResponse
    {
        $this->authorizeOwnership($request, $chat);

        $data = $request->validate(['monitorado' => ['required', 'boolean']]);

        $chat->update([
            'monitorado' => $data['monitorado'],
            'monitorado_em' => $data['monitorado'] ? now() : null,
        ]);

        return response()->json(['chat' => $chat]);
    }

    public function arquivar(Request $request, WhatsappChat $chat): JsonResponse
    {
        $this->authorizeOwnership($request, $chat);

        $data = $request->validate(['arquivado' => ['required', 'boolean']]);
        $chat->update(['arquivado' => $data['arquivado']]);

        return response()->json(['chat' => $chat]);
    }

    /**
     * Score de dívida de atenção: quem espera resposta SUA há mais tempo e
     * quem VOCÊ está esperando (entre os monitorados).
     */
    public function atencao(Request $request): JsonResponse
    {
        $instancia = $this->instanciaDoUsuario($request);
        if ($instancia === null) {
            return response()->json(['aguardando_voce' => [], 'aguardando_eles' => []]);
        }

        $baseQuery = fn () => WhatsappChat::where('instancia_id', $instancia->id)
            ->where('arquivado', false)
            ->when($instancia->phone !== null, fn ($q) => $q->where(fn ($qq) => $qq
                ->whereNull('phone')->orWhere('phone', '!=', $instancia->phone)));

        $aguardandoVoce = $baseQuery()
            ->where('last_message_from_me', false)
            ->whereNotNull('last_inbound_at')
            ->where(fn ($q) => $q->where('is_group', false)->orWhere('monitorado', true))
            ->orderBy('last_inbound_at')
            ->limit(30)
            ->get()
            ->map(fn (WhatsappChat $c) => $this->itemAtencao($c, $c->last_inbound_at));

        $aguardandoEles = $baseQuery()
            ->where('monitorado', true)
            ->where('last_message_from_me', true)
            ->whereNotNull('last_message_at')
            ->orderBy('last_message_at')
            ->limit(30)
            ->get()
            ->map(fn (WhatsappChat $c) => $this->itemAtencao($c, $c->last_message_at));

        return response()->json([
            'aguardando_voce' => $aguardandoVoce,
            'aguardando_eles' => $aguardandoEles,
        ]);
    }

    private function itemAtencao(WhatsappChat $chat, $desde): array
    {
        return [
            'chat_id' => $chat->id,
            'nome' => $chat->nomeExibicao(),
            'monitorado' => $chat->monitorado,
            'is_group' => $chat->is_group,
            'ultima_mensagem' => $chat->last_message_text,
            'desde' => $desde?->toIso8601String(),
            'horas' => $desde !== null ? (int) $desde->diffInHours(now()) : null,
        ];
    }

    private function instanciaDoUsuario(Request $request): ?WhatsappInstancia
    {
        return WhatsappInstancia::where('user_id', $request->user()->id)->first();
    }

    private function authorizeOwnership(Request $request, WhatsappChat $chat): void
    {
        $chat->loadMissing('instancia');
        abort_unless(
            $chat->instancia !== null && (int) $chat->instancia->user_id === (int) $request->user()->id,
            403,
            'Este chat não pertence a você.',
        );
    }
}
