<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chamadas ao Claude para o módulo WhatsApp: relatório diário/matinal, análise
 * de conversa sob demanda e extração de tarefa de uma nota GTD. Mesmo padrão
 * do App\Services\ReceiptAI\ReceiptParser (tool use forçado -> JSON validado).
 */
class WhatsappAnaliseService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * Gera o relatório (diario|matinal) a partir das conversas coletadas.
     *
     * @param  list<array{chat_id:int,nome:string,monitorado:bool,is_group:bool,horas_sem_resposta:int|null,mensagens:list<array{quando:string,de:string,texto:string}>}>  $conversas
     * @return array{resumo:string,pendencias:list<array<string,mixed>>,promessas:list<array<string,mixed>>,assuntos_perdidos:list<array<string,mixed>>,sugestoes_tarefas:list<array<string,mixed>>}
     */
    public function gerarRelatorio(array $conversas, string $tipo): array
    {
        $transcricao = $this->montarTranscricao($conversas);

        $payload = $this->chamarClaude(
            $this->promptRelatorio($tipo),
            "Conversas a analisar:\n\n{$transcricao}",
            $this->toolRelatorio(),
            'registrar_relatorio',
        );

        return [
            'resumo' => (string) ($payload['resumo'] ?? ''),
            'pendencias' => $this->listaDeArrays($payload['pendencias'] ?? []),
            'promessas' => $this->listaDeArrays($payload['promessas'] ?? []),
            'assuntos_perdidos' => $this->listaDeArrays($payload['assuntos_perdidos'] ?? []),
            'sugestoes_tarefas' => $this->normalizarSugestoes($payload['sugestoes_tarefas'] ?? []),
        ];
    }

    /**
     * Análise sob demanda de uma única conversa.
     *
     * @return array{resumo:string,pontos_de_atencao:list<string>,sugestoes_tarefas:list<array<string,mixed>>}
     */
    public function analisarConversa(array $conversa): array
    {
        $transcricao = $this->montarTranscricao([$conversa]);
        $hoje = now(config('whatsapp.relatorio.timezone'))->format('d/m/Y');

        $prompt = <<<TXT
Você é o assistente pessoal de produtividade do Lucas. Hoje é {$hoje}.
Analise a conversa de WhatsApp abaixo e chame a ferramenta registrar_analise com:
- resumo: o que está acontecendo nesta conversa e qual o estado atual (2-4 frases, direto).
- pontos_de_atencao: o que exige ação ou atenção do Lucas (perguntas sem resposta, promessas feitas, prazos citados, decisões pendentes). Vazio se não houver nada.
- sugestoes_tarefas: tarefas concretas e acionáveis que o Lucas deveria criar a partir desta conversa. Título curto no imperativo ("Enviar orçamento ao João"). Só sugira o que realmente merece virar tarefa; ignore conversa social. Se um prazo foi mencionado ("sexta", "amanhã"), converta para data YYYY-MM-DD relativa a hoje.

Responda em português do Brasil.
TXT;

        $payload = $this->chamarClaude(
            $prompt,
            "Conversa a analisar:\n\n{$transcricao}",
            $this->toolAnalise(),
            'registrar_analise',
        );

        return [
            'resumo' => (string) ($payload['resumo'] ?? ''),
            'pontos_de_atencao' => array_values(array_filter(array_map(
                fn ($p) => is_string($p) ? trim($p) : '',
                is_array($payload['pontos_de_atencao'] ?? null) ? $payload['pontos_de_atencao'] : [],
            ))),
            'sugestoes_tarefas' => $this->normalizarSugestoes($payload['sugestoes_tarefas'] ?? []),
        ];
    }

    /**
     * Converte uma nota GTD (mensagem para si mesmo) em tarefa estruturada.
     *
     * @return array{titulo:string,descricao:string|null,prioridade:string,due_date:string|null}
     */
    public function extrairTarefaDeNota(string $nota): array
    {
        $hoje = now(config('whatsapp.relatorio.timezone'));
        $contexto = $hoje->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY');

        $prompt = <<<TXT
Você transforma notas rápidas que o Lucas manda para si mesmo no WhatsApp em tarefas de um kanban pessoal. Hoje é {$contexto}.
Chame a ferramenta registrar_tarefa com:
- titulo: curto, no imperativo, sem datas ("Renovar o domínio do site").
- descricao: detalhes extras da nota, ou null se o título já diz tudo.
- prioridade: 'urgent' só se a nota indicar urgência explícita; senão 'high', 'medium' (padrão) ou 'low'.
- due_date: se a nota citar prazo ("amanhã", "sexta", "dia 15"), a data YYYY-MM-DD relativa a hoje; senão null.

Responda em português do Brasil.
TXT;

        try {
            $payload = $this->chamarClaude($prompt, "Nota: {$nota}", $this->toolTarefaGtd(), 'registrar_tarefa');
        } catch (\Throwable) {
            // IA indisponível não pode segurar a captura da nota: fallback cru.
            return [
                'titulo' => mb_substr(trim(strtok($nota, "\n") ?: $nota), 0, 120),
                'descricao' => mb_strlen(trim($nota)) > 120 ? trim($nota) : null,
                'prioridade' => 'medium',
                'due_date' => null,
            ];
        }

        $titulo = trim((string) ($payload['titulo'] ?? ''));
        if ($titulo === '') {
            $titulo = mb_substr(trim(strtok($nota, "\n") ?: $nota), 0, 120);
        }

        $prioridade = (string) ($payload['prioridade'] ?? 'medium');
        if (! in_array($prioridade, ['low', 'medium', 'high', 'urgent'], true)) {
            $prioridade = 'medium';
        }

        $dueDate = $payload['due_date'] ?? null;
        if (! is_string($dueDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = null;
        }

        $descricao = $payload['descricao'] ?? null;

        return [
            'titulo' => mb_substr($titulo, 0, 255),
            'descricao' => is_string($descricao) && trim($descricao) !== '' ? trim($descricao) : null,
            'prioridade' => $prioridade,
            'due_date' => $dueDate,
        ];
    }

    // ============================================================
    // Prompts e tools
    // ============================================================

    private function promptRelatorio(string $tipo): string
    {
        $tz = config('whatsapp.relatorio.timezone');
        $agora = now($tz)->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY [às] HH:mm');

        $missao = $tipo === 'matinal'
            ? <<<'TXT'
Este é o BRIEFING MATINAL: o Lucas está começando o dia. Foque no que ficou pendente de ontem e no que ele precisa fazer hoje: quem ele precisa responder logo cedo, follow-ups que deve puxar, promessas com prazo chegando.
TXT
            : <<<'TXT'
Este é o RELATÓRIO DE FIM DE DIA: o Lucas está encerrando o expediente. Faça a auditoria do dia: quem ficou sem resposta, o que ele prometeu e não entregou, assuntos importantes que morreram no meio da conversa.
TXT;

        return <<<TXT
Você é o assistente pessoal de produtividade do Lucas. Agora é {$agora}.
Você recebe conversas de WhatsApp dele. Conversas marcadas como MONITORADO são as pessoas/assuntos que ele considera importantes; dê prioridade a elas. "Eu" nas mensagens é o próprio Lucas.

{$missao}

Chame a ferramenta registrar_relatorio com:
- resumo: 2-4 frases com o retrato geral (tom direto, sem rodeios, pode usar "você").
- pendencias: conversas em que a ÚLTIMA palavra é do contato e o Lucas não respondeu — especialmente perguntas diretas e pedidos. Urgência 'alta' quando há prazo, dinheiro ou cliente esperando.
- promessas: compromissos que o LUCAS assumiu nas conversas ("te mando amanhã", "vou verificar") e que não aparecem cumpridos.
- assuntos_perdidos: tópicos importantes que simplesmente pararam no meio (de qualquer lado), e que valem ser retomados.
- sugestoes_tarefas: tarefas concretas e acionáveis para o kanban. Título curto no imperativo. Prazos citados viram due_date (YYYY-MM-DD). Só o que realmente importa — nada de tarefa para conversa social.

Regras:
- Ignore small talk, correntes, figurinhas e grupos irrelevantes.
- Não invente nada que não esteja nas conversas.
- Use o chat_id informado no cabeçalho de cada conversa.
- Responda em português do Brasil.
TXT;
    }

    private function toolRelatorio(): array
    {
        $sugestaoSchema = $this->schemaSugestaoTarefa();

        return [
            'name' => 'registrar_relatorio',
            'description' => 'Registra o relatório estruturado de produtividade das conversas de WhatsApp.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'resumo' => ['type' => 'string'],
                    'pendencias' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'chat_id' => ['type' => ['integer', 'null']],
                                'contato' => ['type' => 'string'],
                                'assunto' => ['type' => 'string'],
                                'ultima_mensagem' => ['type' => 'string'],
                                'horas_esperando' => ['type' => ['number', 'null']],
                                'urgencia' => ['type' => 'string', 'enum' => ['alta', 'media', 'baixa']],
                            ],
                            'required' => ['contato', 'assunto', 'urgencia'],
                        ],
                    ],
                    'promessas' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'chat_id' => ['type' => ['integer', 'null']],
                                'contato' => ['type' => 'string'],
                                'promessa' => ['type' => 'string'],
                                'prazo_mencionado' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['contato', 'promessa'],
                        ],
                    ],
                    'assuntos_perdidos' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'chat_id' => ['type' => ['integer', 'null']],
                                'contato' => ['type' => 'string'],
                                'assunto' => ['type' => 'string'],
                            ],
                            'required' => ['contato', 'assunto'],
                        ],
                    ],
                    'sugestoes_tarefas' => ['type' => 'array', 'items' => $sugestaoSchema],
                ],
                'required' => ['resumo', 'pendencias', 'promessas', 'assuntos_perdidos', 'sugestoes_tarefas'],
            ],
        ];
    }

    private function toolAnalise(): array
    {
        return [
            'name' => 'registrar_analise',
            'description' => 'Registra a análise de uma conversa de WhatsApp.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'resumo' => ['type' => 'string'],
                    'pontos_de_atencao' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sugestoes_tarefas' => ['type' => 'array', 'items' => $this->schemaSugestaoTarefa()],
                ],
                'required' => ['resumo', 'pontos_de_atencao', 'sugestoes_tarefas'],
            ],
        ];
    }

    private function toolTarefaGtd(): array
    {
        return [
            'name' => 'registrar_tarefa',
            'description' => 'Registra a tarefa extraída de uma nota rápida.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'titulo' => ['type' => 'string'],
                    'descricao' => ['type' => ['string', 'null']],
                    'prioridade' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                    'due_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['titulo', 'prioridade'],
            ],
        ];
    }

    private function schemaSugestaoTarefa(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chat_id' => ['type' => ['integer', 'null']],
                'contato' => ['type' => ['string', 'null']],
                'titulo' => ['type' => 'string'],
                'descricao' => ['type' => ['string', 'null']],
                'prioridade' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                'due_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            ],
            'required' => ['titulo', 'prioridade'],
        ];
    }

    // ============================================================
    // Infra
    // ============================================================

    /**
     * @param  list<array{chat_id:int,nome:string,monitorado:bool,is_group:bool,horas_sem_resposta:int|null,mensagens:list<array{quando:string,de:string,texto:string}>}>  $conversas
     */
    private function montarTranscricao(array $conversas): string
    {
        $blocos = [];
        foreach ($conversas as $conversa) {
            $marcas = [];
            if ($conversa['monitorado'] ?? false) {
                $marcas[] = 'MONITORADO';
            }
            if ($conversa['is_group'] ?? false) {
                $marcas[] = 'grupo';
            }
            if (($conversa['horas_sem_resposta'] ?? null) !== null) {
                $marcas[] = "sem resposta há {$conversa['horas_sem_resposta']}h";
            }
            $sufixo = $marcas !== [] ? ' ['.implode(', ', $marcas).']' : '';

            $linhas = ["### chat_id={$conversa['chat_id']} | {$conversa['nome']}{$sufixo}"];
            foreach ($conversa['mensagens'] as $m) {
                $linhas[] = "[{$m['quando']}] {$m['de']}: {$m['texto']}";
            }
            $blocos[] = implode("\n", $linhas);
        }

        return implode("\n\n", $blocos);
    }

    /**
     * @return array<string, mixed>
     */
    private function chamarClaude(string $systemPrompt, string $conteudo, array $tool, string $toolName): array
    {
        $key = config('services.anthropic.key');
        if (empty($key)) {
            throw new RuntimeException('A análise por IA não está configurada. Defina ANTHROPIC_API_KEY no .env do backend.');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])->timeout(120)->post(self::ENDPOINT, [
                'model' => config('whatsapp.ia.model'),
                'max_tokens' => 8192,
                'system' => $systemPrompt,
                'tools' => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => $conteudo]],
                ]],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Não foi possível contatar o serviço de IA. Tente novamente.', 0, $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? 'erro desconhecido';
            throw new RuntimeException("A IA recusou a análise ({$detail}).");
        }

        foreach ($response->json('content') ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new RuntimeException('A IA não retornou a análise estruturada. Tente novamente.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listaDeArrays(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter($valor, 'is_array'));
    }

    /**
     * @return list<array{chat_id:int|null,contato:string|null,titulo:string,descricao:string|null,prioridade:string,due_date:string|null}>
     */
    private function normalizarSugestoes(mixed $sugestoes): array
    {
        $itens = [];
        foreach ($this->listaDeArrays($sugestoes) as $s) {
            $titulo = trim((string) ($s['titulo'] ?? ''));
            if ($titulo === '') {
                continue;
            }

            $prioridade = (string) ($s['prioridade'] ?? 'medium');
            if (! in_array($prioridade, ['low', 'medium', 'high', 'urgent'], true)) {
                $prioridade = 'medium';
            }

            $dueDate = $s['due_date'] ?? null;
            if (! is_string($dueDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                $dueDate = null;
            }

            $descricao = $s['descricao'] ?? null;
            $contato = $s['contato'] ?? null;

            $itens[] = [
                'chat_id' => isset($s['chat_id']) && is_numeric($s['chat_id']) ? (int) $s['chat_id'] : null,
                'contato' => is_string($contato) && trim($contato) !== '' ? trim($contato) : null,
                'titulo' => mb_substr($titulo, 0, 255),
                'descricao' => is_string($descricao) && trim($descricao) !== '' ? trim($descricao) : null,
                'prioridade' => $prioridade,
                'due_date' => $dueDate,
            ];
        }

        return $itens;
    }
}
