<?php

namespace App\Services\Whatsapp;

use App\Models\User;
use App\Models\WhatsappChat;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappRelatorio;
use App\Models\WhatsappSugestao;
use App\Services\Saude\SaudeNutricaoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Monta e envia os relatórios do módulo WhatsApp (fim de dia e briefing
 * matinal): coleta as conversas relevantes, chama a IA, persiste o relatório,
 * cria as sugestões de tarefa e manda o resumo pelo próprio WhatsApp.
 */
class WhatsappRelatorioService
{
    private const MAX_MENSAGENS_POR_CHAT = 60;
    private const MAX_CHARS_POR_MENSAGEM = 500;
    private const MAX_CHATS_AGUARDANDO = 15;

    public function __construct(
        private WhatsappAnaliseService $analise,
        private WhatsappSender $sender,
        private SaudeNutricaoService $nutricao,
    ) {
    }

    /**
     * Gera (ou regenera) o relatório do dia e envia pelo WhatsApp.
     */
    public function gerarEEnviar(User $user, string $tipo, bool $enviar = true): WhatsappRelatorio
    {
        $instancia = WhatsappInstancia::where('user_id', $user->id)->first();
        if ($instancia === null) {
            throw new RuntimeException('Nenhuma instância de WhatsApp configurada para este usuário.');
        }

        $conversas = $this->coletarConversas($instancia, $tipo);

        if ($conversas === []) {
            $dados = [
                'resumo' => 'Nenhuma conversa relevante para analisar no período. Caixa limpa! 🎉',
                'pendencias' => [],
                'promessas' => [],
                'assuntos_perdidos' => [],
                'sugestoes_tarefas' => [],
            ];
        } else {
            $dados = $this->analise->gerarRelatorio($conversas, $tipo);
        }

        $tz = config('whatsapp.relatorio.timezone');
        $hoje = now($tz)->toDateString();
        $texto = $this->montarTextoWhatsapp($dados, $tipo);

        // Briefing matinal fecha com o resumo nutricional (módulo Saúde).
        if ($tipo === 'matinal') {
            $secaoNutricao = $this->secaoNutricao($user);
            if ($secaoNutricao !== null) {
                $texto .= "\n\n".$secaoNutricao;
            }
        }

        $relatorio = DB::transaction(function () use ($user, $tipo, $hoje, $dados, $texto, $conversas) {
            $relatorio = WhatsappRelatorio::updateOrCreate(
                ['user_id' => $user->id, 'tipo' => $tipo, 'referencia_data' => $hoje],
                ['dados' => $dados, 'texto_whatsapp' => $texto],
            );

            // Regerar substitui as sugestões pendentes deste relatório.
            WhatsappSugestao::where('relatorio_id', $relatorio->id)
                ->where('status', 'pendente')
                ->delete();

            $chatIdsValidos = array_column($conversas, 'chat_id');
            foreach ($dados['sugestoes_tarefas'] as $sugestao) {
                $chatId = $sugestao['chat_id'] ?? null;
                WhatsappSugestao::create([
                    'user_id' => $user->id,
                    'chat_id' => in_array($chatId, $chatIdsValidos, true) ? $chatId : null,
                    'relatorio_id' => $relatorio->id,
                    'origem' => 'relatorio',
                    'titulo' => $sugestao['titulo'],
                    'descricao' => $sugestao['descricao'],
                    'prioridade' => $sugestao['prioridade'],
                    'due_date' => $sugestao['due_date'],
                    'contexto' => $sugestao['contato'] !== null ? "Conversa com {$sugestao['contato']}" : null,
                ]);
            }

            return $relatorio;
        });

        if ($enviar && $this->sender->enviarParaMim($instancia, $texto)) {
            $relatorio->update(['enviado_em' => now()]);
        }

        return $relatorio->refresh();
    }

    /**
     * Coleta as conversas relevantes: monitoradas com movimento recente + quem
     * está aguardando resposta sua. O chat consigo mesmo (notas GTD) fica fora.
     *
     * @return list<array{chat_id:int,nome:string,monitorado:bool,is_group:bool,horas_sem_resposta:int|null,mensagens:list<array{quando:string,de:string,texto:string}>}>
     */
    public function coletarConversas(WhatsappInstancia $instancia, string $tipo): array
    {
        $tz = config('whatsapp.relatorio.timezone');
        $horasJanela = $tipo === 'matinal' ? 36 : 24;
        $desde = now()->subHours($horasJanela);
        $horasAguardando = (int) config('whatsapp.relatorio.horas_aguardando');

        $monitorados = WhatsappChat::where('instancia_id', $instancia->id)
            ->where('monitorado', true)
            ->where('arquivado', false)
            ->where('last_message_at', '>=', $desde)
            ->orderByDesc('last_message_at')
            ->get();

        $aguardando = WhatsappChat::where('instancia_id', $instancia->id)
            ->where('arquivado', false)
            ->where('monitorado', false)
            ->where('is_group', false)
            ->where('last_message_from_me', false)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', now()->subHours($horasAguardando))
            ->where('last_inbound_at', '>=', now()->subDays(7))
            ->orderBy('last_inbound_at')
            ->limit(self::MAX_CHATS_AGUARDANDO)
            ->get();

        $conversas = [];
        foreach ($monitorados->concat($aguardando) as $chat) {
            // Notas para si mesmo não são conversa.
            if ($chat->phone !== null && $chat->phone === $instancia->phone) {
                continue;
            }

            $mensagens = $chat->mensagens()
                ->orderByDesc('momment')
                ->limit(self::MAX_MENSAGENS_POR_CHAT)
                ->get()
                ->reverse()
                ->values();

            if ($mensagens->isEmpty()) {
                continue;
            }

            $horasSemResposta = null;
            if (! $chat->last_message_from_me && $chat->last_inbound_at !== null) {
                $horasSemResposta = (int) $chat->last_inbound_at->diffInHours(now());
            }

            $conversas[] = [
                'chat_id' => $chat->id,
                'nome' => $chat->nomeExibicao(),
                'monitorado' => $chat->monitorado,
                'is_group' => $chat->is_group,
                'horas_sem_resposta' => $horasSemResposta,
                'mensagens' => $mensagens->map(function ($m) use ($tz) {
                    $quando = $m->momment > 0
                        ? now($tz)->setTimestamp(intdiv($m->momment, 1000))->format('d/m H:i')
                        : $m->created_at->timezone($tz)->format('d/m H:i');

                    $de = $m->from_me ? 'Eu' : ($m->sender_name ?: 'Contato');
                    $texto = mb_substr(trim((string) $m->texto), 0, self::MAX_CHARS_POR_MENSAGEM);

                    return ['quando' => $quando, 'de' => $de, 'texto' => $texto];
                })->all(),
            ];
        }

        return $conversas;
    }

    /**
     * Versão compacta do relatório para mandar como mensagem de WhatsApp.
     */
    public function montarTextoWhatsapp(array $dados, string $tipo): string
    {
        $tz = config('whatsapp.relatorio.timezone');
        $data = now($tz)->format('d/m');

        $linhas = [];
        $linhas[] = $tipo === 'matinal'
            ? "☀️ *Briefing da manhã — {$data}*"
            : "🌙 *Relatório do dia — {$data}*";

        $resumo = trim((string) ($dados['resumo'] ?? ''));
        if ($resumo !== '') {
            $linhas[] = '';
            $linhas[] = $resumo;
        }

        if (($dados['pendencias'] ?? []) !== []) {
            $linhas[] = '';
            $linhas[] = '⚠️ *Aguardando sua resposta:*';
            foreach ($dados['pendencias'] as $p) {
                $horas = isset($p['horas_esperando']) && $p['horas_esperando'] !== null
                    ? ' ('.round((float) $p['horas_esperando']).'h)'
                    : '';
                $urgencia = ($p['urgencia'] ?? '') === 'alta' ? ' 🔴' : '';
                $linhas[] = "• {$p['contato']}{$horas} — {$p['assunto']}{$urgencia}";
            }
        }

        if (($dados['promessas'] ?? []) !== []) {
            $linhas[] = '';
            $linhas[] = '🤝 *Promessas suas em aberto:*';
            foreach ($dados['promessas'] as $p) {
                $prazo = isset($p['prazo_mencionado']) && $p['prazo_mencionado'] !== null
                    ? " ({$p['prazo_mencionado']})"
                    : '';
                $linhas[] = "• {$p['contato']} — {$p['promessa']}{$prazo}";
            }
        }

        if (($dados['assuntos_perdidos'] ?? []) !== []) {
            $linhas[] = '';
            $linhas[] = '🧵 *Assuntos que morreram no meio:*';
            foreach ($dados['assuntos_perdidos'] as $a) {
                $linhas[] = "• {$a['contato']} — {$a['assunto']}";
            }
        }

        $sugestoes = $dados['sugestoes_tarefas'] ?? [];
        if ($sugestoes !== []) {
            $linhas[] = '';
            $linhas[] = '💡 *Sugestões de tarefas* (aceite no painel):';
            foreach ($sugestoes as $s) {
                $due = $s['due_date'] !== null ? ' 📅 '.substr($s['due_date'], 8, 2).'/'.substr($s['due_date'], 5, 2) : '';
                $linhas[] = "• {$s['titulo']}{$due}";
            }
        }

        return implode("\n", $linhas);
    }

    /**
     * Fechamento nutricional do briefing matinal: ontem vs meta, meta de hoje e
     * projeção de peso. Null quando não há nada a mostrar; erro aqui nunca
     * derruba o briefing.
     */
    private function secaoNutricao(User $user): ?string
    {
        try {
            $tz = config('saude.timezone');
            $ontem = now($tz)->subDay();
            $resumo = $this->nutricao->resumoDia($user, $ontem->toDateString());

            $temRefeicoes = $resumo['refeicoes']->isNotEmpty();
            if (! $temRefeicoes && ! $resumo['perfil_completo']) {
                return null;
            }

            $kcal = fn (int $v): string => number_format($v, 0, ',', '.');
            $g = function (float $v): string {
                return number_format($v, $v == floor($v) ? 0 : 1, ',', '.').'g';
            };

            $consumido = $resumo['consumido'];
            $metas = $resumo['metas'];

            $linhas = ['🍎 *Nutrição — ontem ('.$ontem->format('d/m').'):*'];

            if ($temRefeicoes) {
                $linha = $kcal($consumido['calorias']).' kcal';
                if ($metas['calorias'] !== null) {
                    $saldo = $metas['calorias'] - $consumido['calorias'];
                    $linha .= ' de '.$kcal($metas['calorias']);
                    $linha .= $saldo >= 0
                        ? ' (sobraram '.$kcal($saldo).')'
                        : ' (estourou '.$kcal(abs($saldo)).')';
                }
                if ($metas['proteinas_g'] !== null) {
                    $linha .= ' · Proteínas '.$g((float) $consumido['proteinas_g']).' de '.$g((float) $metas['proteinas_g']);
                }
                $linhas[] = $linha;
            } else {
                $linhas[] = 'Nenhuma refeição registrada ontem — manda a foto do prato que eu conto as calorias. 📸';
            }

            if ($metas['calorias'] !== null) {
                $meta = '🎯 Hoje: até '.$kcal($metas['calorias']).' kcal';
                if ($metas['proteinas_g'] !== null) {
                    $meta .= ' e '.$g((float) $metas['proteinas_g']).' de proteína';
                }
                $linhas[] = $meta;
            }

            $projecao = $this->nutricao->projecao($user);
            if ($projecao !== null && $projecao['peso_meta'] !== null && $projecao['data_prevista_meta'] !== null) {
                $prevista = substr($projecao['data_prevista_meta'], 8, 2).'/'.substr($projecao['data_prevista_meta'], 5, 2);
                $ritmo = number_format(abs((float) $projecao['ritmo_real_kg_semana']), 2, ',', '.');
                $linhas[] = "⚖️ No ritmo atual ({$ritmo} kg/semana) você chega aos ".number_format((float) $projecao['peso_meta'], 1, ',', '.')." kg em {$prevista}.";
            }

            return implode("\n", $linhas);
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:relatorio] seção de nutrição falhou: '.$e->getMessage());

            return null;
        }
    }
}
