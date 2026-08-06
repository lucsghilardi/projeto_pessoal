<?php

namespace App\Jobs;

use App\Models\SaudeRefeicao;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappSugestao;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Saude\SaudeNutricaoService;
use App\Services\Whatsapp\WhatsappAnaliseService;
use App\Services\Whatsapp\WhatsappSender;
use App\Services\Whatsapp\WhatsappTaskBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Inbox GTD: transforma uma nota enviada para si mesmo no WhatsApp em tarefa
 * do kanban e responde com uma confirmação ✅ no próprio chat. Com o modo
 * calorias_texto_ia ativo, a IA decide antes se a nota é uma refeição do
 * diário alimentar — nesse caso registra a refeição e responde com o dia.
 */
class ProcessarInboxGtd implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $mensagemId)
    {
    }

    public function handle(
        WhatsappAnaliseService $analise,
        SaudeNutricaoAI $nutricaoIa,
        SaudeNutricaoService $nutricao,
        WhatsappTaskBridge $bridge,
        WhatsappSender $sender,
    ): void {
        $mensagem = WhatsappMensagem::with('instancia.user', 'chat')->find($this->mensagemId);
        if ($mensagem === null || $mensagem->instancia === null) {
            return;
        }

        $user = $mensagem->instancia->user;
        $nota = trim((string) $mensagem->texto);
        if ($user === null || $nota === '') {
            return;
        }

        // Evita tarefa/refeição duplicada se o job repetir após falha parcial.
        $jaProcessada = WhatsappSugestao::where('user_id', $user->id)
            ->where('origem', 'gtd')
            ->where('contexto', "mensagem:{$mensagem->id}")
            ->exists()
            || SaudeRefeicao::where('whatsapp_mensagem_id', $mensagem->id)->exists();
        if ($jaProcessada) {
            return;
        }

        if ($mensagem->instancia->calorias_texto_ia) {
            $rota = $nutricaoIa->rotearNota($nota);

            if ($rota['tipo'] === 'refeicao') {
                $refeicao = ProcessarRefeicaoWhatsapp::criarDesdeAnalise($user, $mensagem, $rota['refeicao'], 'whatsapp_texto');
                $resposta = ProcessarRefeicaoWhatsapp::montarResposta(
                    $refeicao,
                    $nutricao->resumoDia($user, $refeicao->data->toDateString()),
                );

                if (! $sender->enviarParaMim($mensagem->instancia, $resposta)) {
                    Log::warning('[whatsapp:calorias] refeição registrada, mas a resposta não foi enviada.');
                }

                return;
            }

            $dados = $rota['tarefa'];
        } else {
            $dados = $analise->extrairTarefaDeNota($nota);
        }

        $task = $bridge->criarTarefa($user, $dados);

        // Registro da captura (já aceita — a tarefa foi criada direto).
        WhatsappSugestao::create([
            'user_id' => $user->id,
            'chat_id' => $mensagem->chat_id,
            'origem' => 'gtd',
            'titulo' => $dados['titulo'],
            'descricao' => $dados['descricao'],
            'prioridade' => $dados['prioridade'],
            'due_date' => $dados['due_date'],
            'contexto' => "mensagem:{$mensagem->id}",
            'status' => 'aceita',
            'task_id' => $task->id,
        ]);

        $confirmacao = "✅ Tarefa criada: {$dados['titulo']}";
        if ($dados['due_date'] !== null) {
            $confirmacao .= ' (prazo '.substr($dados['due_date'], 8, 2).'/'.substr($dados['due_date'], 5, 2).')';
        }

        if (! $sender->enviarParaMim($mensagem->instancia, $confirmacao)) {
            Log::warning('[whatsapp:gtd] tarefa criada, mas a confirmação não foi enviada.');
        }
    }
}
