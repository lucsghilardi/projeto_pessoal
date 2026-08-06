<?php

namespace App\Services\Saude;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chamadas ao Claude para o diário alimentar: análise de foto de prato (vision),
 * análise de descrição textual e o roteador comida-vs-tarefa do chat GTD.
 * Mesmo padrão do ReceiptParser/WhatsappAnaliseService (tool use forçado).
 */
class SaudeNutricaoAI
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const TIPOS_REFEICAO = ['cafe_da_manha', 'almoco', 'jantar', 'lanche', 'outro'];
    private const NIVEIS_CONFIANCA = ['alta', 'media', 'baixa'];
    private const MAX_CALORIAS_REFEICAO = 5000;

    /**
     * Analisa a foto de um prato. `e_comida` false quando a imagem não é comida.
     *
     * @return array{e_comida: bool, nome: string, tipo: string, itens: list<array<string, mixed>>, calorias: int, proteinas_g: float, carboidratos_g: float|null, gorduras_g: float|null, confianca: string}
     */
    public function analisarFoto(string $binary, string $mime, ?string $caption = null): array
    {
        $blocos = [[
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $this->normalizarMimeImagem($mime),
                'data' => base64_encode($binary),
            ],
        ]];

        $texto = 'Analise a refeição da foto acima.';
        if ($caption !== null && trim($caption) !== '') {
            $texto .= "\nLegenda enviada junto com a foto: ".trim($caption);
        }
        $blocos[] = ['type' => 'text', 'text' => $texto];

        $payload = $this->chamarClaude(
            $this->promptRefeicao('foto'),
            $blocos,
            $this->toolRefeicao(),
            'registrar_refeicao',
        );

        return $this->normalizarRefeicao($payload);
    }

    /**
     * Analisa uma descrição textual de refeição ("2 ovos mexidos e café com leite").
     *
     * @return array{e_comida: bool, nome: string, tipo: string, itens: list<array<string, mixed>>, calorias: int, proteinas_g: float, carboidratos_g: float|null, gorduras_g: float|null, confianca: string}
     */
    public function analisarTexto(string $texto): array
    {
        $payload = $this->chamarClaude(
            $this->promptRefeicao('texto'),
            [['type' => 'text', 'text' => "Refeição descrita: {$texto}"]],
            $this->toolRefeicao(),
            'registrar_refeicao',
        );

        return $this->normalizarRefeicao($payload);
    }

    /**
     * Modo "IA decide" do chat consigo mesmo: classifica a nota como refeição
     * (diário alimentar) ou tarefa (GTD) em uma única chamada. Em falha da IA a
     * nota NUNCA se perde: vira tarefa com o mesmo fallback cru do GTD.
     *
     * @return array{tipo: string, refeicao: array<string, mixed>|null, tarefa: array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}|null}
     */
    public function rotearNota(string $nota): array
    {
        try {
            $payload = $this->chamarClaude(
                $this->promptRoteador(),
                [['type' => 'text', 'text' => "Nota: {$nota}"]],
                $this->toolRoteador(),
                'processar_nota',
            );
        } catch (\Throwable) {
            return ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => $this->tarefaFallback($nota)];
        }

        $tipo = (string) ($payload['tipo'] ?? 'tarefa');

        if ($tipo === 'refeicao' && is_array($payload['refeicao'] ?? null)) {
            $refeicao = $this->normalizarRefeicao($payload['refeicao']);
            if ($refeicao['e_comida']) {
                return ['tipo' => 'refeicao', 'refeicao' => $refeicao, 'tarefa' => null];
            }
        }

        $tarefa = is_array($payload['tarefa'] ?? null)
            ? $this->normalizarTarefa($payload['tarefa'], $nota)
            : $this->tarefaFallback($nota);

        return ['tipo' => 'tarefa', 'refeicao' => null, 'tarefa' => $tarefa];
    }

    // ============================================================
    // Prompts e tools
    // ============================================================

    private function promptRefeicao(string $origem): string
    {
        $agora = now(config('saude.timezone'))->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY [às] HH:mm');
        $fonte = $origem === 'foto'
            ? 'a foto de uma refeição que o Lucas mandou pelo WhatsApp'
            : 'a descrição em texto de uma refeição que o Lucas consumiu';

        return <<<TXT
Você é um nutricionista experiente registrando o diário alimentar do Lucas. Agora é {$agora}. Você recebe {$fonte}.
Chame a ferramenta registrar_refeicao com sua melhor estimativa:
- e_comida: false se não houver comida ou bebida identificável (nesse caso ignore os demais campos).
- nome: nome curto e descritivo do prato ("Frango grelhado com arroz e salada").
- tipo: cafe_da_manha, almoco, jantar, lanche ou outro — use o horário atual como pista.
- itens: cada alimento com porção estimada em medidas caseiras brasileiras ("4 colheres de sopa de arroz", "1 filé de frango ~120g") e as calorias e proteínas daquele item.
- calorias, proteinas_g, carboidratos_g, gorduras_g: totais da refeição (coerentes com a soma dos itens).
- confianca: alta (itens claros e porções visíveis), media, ou baixa (porções difíceis de estimar).

Regras:
- Seja realista e levemente conservador: na dúvida, estime as calorias para CIMA (preparos costumam ter mais óleo e açúcar do que aparentam).
- Considere o preparo aparente (frito vs grelhado vs cozido).
- Se houver legenda/observação do usuário, ela corrige o que não dá para ver.
- Responda em português do Brasil.
TXT;
    }

    private function promptRoteador(): string
    {
        $agora = now(config('saude.timezone'))->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY [às] HH:mm');

        return <<<TXT
O Lucas manda mensagens para si mesmo no WhatsApp por dois motivos: registrar o que COMEU (diário alimentar) ou capturar uma tarefa/nota rápida (GTD). Agora é {$agora}.
Classifique a nota e chame a ferramenta processar_nota:
- tipo 'refeicao': a nota descreve comida ou bebida que ele CONSUMIU ("2 ovos e café", "almocei PF com bife", "1 whey com banana").
- tipo 'tarefa': todo o resto — lembretes, ideias, compromissos. Atenção: comida como OBJETO de ação é tarefa ("comprar ovos", "marcar nutricionista", "pesquisar receita de frango").

Se refeicao, preencha o objeto refeicao como um nutricionista experiente: nome curto do prato, tipo (cafe_da_manha|almoco|jantar|lanche|outro, use o horário como pista), itens com porções em medidas caseiras brasileiras e calorias/proteínas por item, totais coerentes e confianca (alta|media|baixa). Na dúvida, estime calorias para cima. e_comida: true.
Se tarefa, preencha o objeto tarefa: titulo curto no imperativo sem datas, descricao com detalhes extras ou null, prioridade ('urgent' só com urgência explícita; senão 'high', 'medium' padrão ou 'low'), due_date YYYY-MM-DD se a nota citar prazo relativo a hoje, senão null.

Responda em português do Brasil.
TXT;
    }

    private function toolRefeicao(): array
    {
        return [
            'name' => 'registrar_refeicao',
            'description' => 'Registra a estimativa nutricional de uma refeição no diário alimentar.',
            'input_schema' => [
                'type' => 'object',
                'properties' => $this->schemaRefeicaoProperties(),
                'required' => ['e_comida', 'nome', 'tipo', 'itens', 'calorias', 'proteinas_g', 'confianca'],
            ],
        ];
    }

    private function toolRoteador(): array
    {
        return [
            'name' => 'processar_nota',
            'description' => 'Classifica uma nota do WhatsApp como refeição consumida ou tarefa, com os dados estruturados correspondentes.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => ['refeicao', 'tarefa']],
                    'refeicao' => [
                        'type' => ['object', 'null'],
                        'properties' => $this->schemaRefeicaoProperties(),
                    ],
                    'tarefa' => [
                        'type' => ['object', 'null'],
                        'properties' => [
                            'titulo' => ['type' => 'string'],
                            'descricao' => ['type' => ['string', 'null']],
                            'prioridade' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                            'due_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                        ],
                    ],
                ],
                'required' => ['tipo'],
            ],
        ];
    }

    private function schemaRefeicaoProperties(): array
    {
        return [
            'e_comida' => ['type' => 'boolean'],
            'nome' => ['type' => 'string'],
            'tipo' => ['type' => 'string', 'enum' => self::TIPOS_REFEICAO],
            'itens' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nome' => ['type' => 'string'],
                        'quantidade' => ['type' => 'string', 'description' => 'Porção em medida caseira, ex: "4 colheres de sopa"'],
                        'calorias' => ['type' => 'integer'],
                        'proteinas_g' => ['type' => 'number'],
                    ],
                    'required' => ['nome', 'quantidade', 'calorias'],
                ],
            ],
            'calorias' => ['type' => 'integer'],
            'proteinas_g' => ['type' => 'number'],
            'carboidratos_g' => ['type' => ['number', 'null']],
            'gorduras_g' => ['type' => ['number', 'null']],
            'confianca' => ['type' => 'string', 'enum' => self::NIVEIS_CONFIANCA],
        ];
    }

    // ============================================================
    // Normalização defensiva
    // ============================================================

    /**
     * @return array{e_comida: bool, nome: string, tipo: string, itens: list<array<string, mixed>>, calorias: int, proteinas_g: float, carboidratos_g: float|null, gorduras_g: float|null, confianca: string}
     */
    private function normalizarRefeicao(array $payload): array
    {
        $nome = mb_substr(trim((string) ($payload['nome'] ?? '')), 0, 120);

        $tipo = (string) ($payload['tipo'] ?? 'outro');
        if (! in_array($tipo, self::TIPOS_REFEICAO, true)) {
            $tipo = 'outro';
        }

        $confianca = (string) ($payload['confianca'] ?? 'media');
        if (! in_array($confianca, self::NIVEIS_CONFIANCA, true)) {
            $confianca = 'media';
        }

        $itens = [];
        if (is_array($payload['itens'] ?? null)) {
            foreach ($payload['itens'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemNome = mb_substr(trim((string) ($item['nome'] ?? '')), 0, 120);
                if ($itemNome === '') {
                    continue;
                }
                $itens[] = [
                    'nome' => $itemNome,
                    'quantidade' => mb_substr(trim((string) ($item['quantidade'] ?? '')), 0, 80),
                    'calorias' => $this->clampInt($item['calorias'] ?? 0, 0, self::MAX_CALORIAS_REFEICAO),
                    'proteinas_g' => $this->clampFloat($item['proteinas_g'] ?? 0, 0, 500),
                ];
            }
        }

        return [
            'e_comida' => (bool) ($payload['e_comida'] ?? false) && $nome !== '',
            'nome' => $nome !== '' ? $nome : 'Refeição',
            'tipo' => $tipo,
            'itens' => $itens,
            'calorias' => $this->clampInt($payload['calorias'] ?? 0, 0, self::MAX_CALORIAS_REFEICAO),
            'proteinas_g' => $this->clampFloat($payload['proteinas_g'] ?? 0, 0, 500),
            'carboidratos_g' => isset($payload['carboidratos_g']) && is_numeric($payload['carboidratos_g'])
                ? $this->clampFloat($payload['carboidratos_g'], 0, 999)
                : null,
            'gorduras_g' => isset($payload['gorduras_g']) && is_numeric($payload['gorduras_g'])
                ? $this->clampFloat($payload['gorduras_g'], 0, 999)
                : null,
            'confianca' => $confianca,
        ];
    }

    /**
     * @return array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}
     */
    private function normalizarTarefa(array $payload, string $nota): array
    {
        $titulo = trim((string) ($payload['titulo'] ?? ''));
        if ($titulo === '') {
            return $this->tarefaFallback($nota);
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

    /**
     * Mesmo fallback cru do GTD: primeira linha vira título, nada se perde.
     *
     * @return array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}
     */
    private function tarefaFallback(string $nota): array
    {
        return [
            'titulo' => mb_substr(trim(strtok($nota, "\n") ?: $nota), 0, 120),
            'descricao' => mb_strlen(trim($nota)) > 120 ? trim($nota) : null,
            'prioridade' => 'medium',
            'due_date' => null,
        ];
    }

    private function clampInt(mixed $valor, int $min, int $max): int
    {
        return max($min, min($max, (int) round(is_numeric($valor) ? (float) $valor : 0)));
    }

    private function clampFloat(mixed $valor, float $min, float $max): float
    {
        return round(max($min, min($max, is_numeric($valor) ? (float) $valor : 0)), 1);
    }

    private function normalizarMimeImagem(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mime
            : 'image/jpeg';
    }

    // ============================================================
    // Infra
    // ============================================================

    /**
     * @param  list<array<string, mixed>>  $contentBlocks
     * @return array<string, mixed>
     */
    private function chamarClaude(string $systemPrompt, array $contentBlocks, array $tool, string $toolName): array
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
                'model' => config('saude.nutricao.model'),
                'max_tokens' => 8192,
                'system' => $systemPrompt,
                'tools' => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
                'messages' => [[
                    'role' => 'user',
                    'content' => $contentBlocks,
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
}
