<?php

namespace App\Services\Saude;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lê uma corrida no papel de personal trainer + nutricionista.
 *
 * Mesmo padrão do SaudeNutricaoAI/ReceiptParser: uma tool forçada, resposta
 * estruturada, passe de normalização por cima. A diferença é o contexto — aqui
 * o dossiê já chega com as contas prontas (pace por volta, deriva cardíaca,
 * percentil, déficit do dia), e o prompt proíbe recalcular ou inventar número.
 */
class SaudeCardioAI
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const MAX_ITENS_LISTA = 5;

    /**
     * @param  array<string, mixed>  $contexto  saída de SaudeCardioAnaliseService::contexto()
     * @return array<string, mixed>
     */
    public function analisar(array $contexto): array
    {
        $dossie = json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $payload = $this->chamarClaude(
            $this->prompt(),
            [['type' => 'text', 'text' => "Dossiê da sessão:\n\n{$dossie}"]],
            $this->tool(),
            'registrar_analise',
        );

        return $this->normalizar($payload, $contexto);
    }

    private function prompt(): string
    {
        return <<<'TXT'
Você é o treinador de corrida e nutricionista esportivo do Lucas, analisando uma sessão que ele acabou de fazer. Fale com ele em segunda pessoa, em português do Brasil, com o tom de quem acompanha o trabalho dele há meses: direto, específico e sem bajulação.

O dossiê em JSON já vem com TODAS as contas feitas: pace por volta, médias por metade, deriva de frequência cardíaca, tempo em cada zona, percentil de VO2max, age grade, volume da semana, sono da noite anterior e nutrição do dia.

Regras inegociáveis:
- NUNCA invente ou recalcule números. Use apenas os valores do dossiê. Se um campo for null, trate como "não medido" e diga isso quando for relevante — não estime.
- Quando citar um número, cite o do dossiê e diga o que ele significa na prática.
- `benchmarks.esforco_maximo` é a chave do comparativo. Ele é false quando a FC média ficou abaixo de 88% da máxima, ou seja: foi treino, não prova.
  - Com esforco_maximo false, `vo2max_desempenho` e `age_grade` medem ESTA CORRIDA, não a forma física. Um treino leve devolve VDOT baixo mesmo em quem é bem condicionado. NUNCA diga que ele está mal colocado, em percentil baixo ou fora de forma com base nesses números. Use `vo2max_relogio` e `percentil` para falar de condicionamento, e trate o age grade como "o que esta corrida valeria como prova".
  - Com esforco_maximo true, aí sim `vo2max_desempenho` e `age_grade` valem como leitura de forma. Se divergirem de `vo2max_relogio` em mais de 5 pontos, aponte no comparativo e explique a causa provável: FC máxima mal configurada, calor ou terreno.
- `benchmarks.projecoes.fonte` diz de onde vêm os tempos previstos: "garmin" é a previsão do próprio relógio; "corrida" foi derivada desta sessão. Nunca apresente projeção derivada de treino leve como meta de prova.
- `benchmarks.fc_maxima_estimada` true significa que a FC máxima é um chute pela idade (fórmula de Tanaka), não medida. Se a análise por zona depender dela, diga que vale fazer um teste de verdade.
- `splits.veredito` "irregular" quer dizer treino intervalado ou percurso com paradas: não leia como má distribuição de esforço.
- Modalidade "esteira" não tem GPS: distância e pace são estimados pelo acelerômetro e merecem ressalva.
- Nutrição: use `nutricao` e `corpo`. Se `nutricao` for null, o Lucas não registrou o dia — diga isso em vez de supor o que ele comeu. Considere o horário da corrida contra o das refeições ao falar de pré e pós-treino.
- Se `recuperacao` mostrar sono curto, HRV baixo ou FC de repouso acima da média de 30 dias, conecte isso ao desempenho antes de cobrar mais intensidade.
- Segurança em primeiro lugar: se os dados sugerirem sobrecarga (volume subindo rápido, FC de repouso subindo, sono ruim persistente), recomende recuo, não mais treino.
- Você não é médico. Diante de sinal preocupante (FC muito acima do esperado para o esforço, dor relatada na observação), sugira procurar um profissional em vez de diagnosticar.

Chame a ferramenta registrar_analise. Seja concreto: "segure o primeiro km em 7:10 para não estourar no terceiro" vale mais que "trabalhe seu pacing".
TXT;
    }

    /**
     * Schema deliberadamente PLANO — nada de objeto dentro de objeto.
     *
     * Com propriedades aninhadas o modelo achata a estrutura na hora de emitir
     * a tool: as chaves internas sobem para o topo e o objeto pai chega como
     * string com marcação crua ("<parameter name=...>"). O texto vinha certo e
     * era descartado na leitura. Campos planos com prefixo evitam isso; o
     * aninhamento que a tela consome é remontado em `normalizar()`.
     *
     * @return array<string, mixed>
     */
    private function tool(): array
    {
        $texto = fn (string $descricao) => ['type' => 'string', 'description' => $descricao];

        return [
            'name' => 'registrar_analise',
            'description' => 'Registra a análise técnica da sessão de corrida.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'nota_geral' => [
                        'type' => 'integer',
                        'description' => 'Nota de 0 a 10 para a sessão, considerando o objetivo e o contexto do dia.',
                    ],
                    'resumo' => $texto('Dois ou três períodos sobre como foi a sessão. Comece pelo que mais importa.'),

                    'pacing_comentario' => $texto('O que a distribuição de ritmo mostra e o que fazer diferente na próxima.'),

                    'esforco_zona_predominante' => $texto('Zona de FC onde passou mais tempo, e o que isso treina.'),
                    'esforco_deriva_cardiaca' => $texto('Leitura da variação de FC entre a primeira e a segunda metade.'),
                    'esforco_comentario' => $texto('O custo fisiológico da sessão e a capacidade que ela desenvolve.'),

                    'pontos_fortes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'De 1 a 3 acertos concretos desta sessão.',
                    ],
                    'pontos_de_atencao' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'De 1 a 3 pontos a corrigir, do mais importante para o menos.',
                    ],
                    'comparativo' => $texto('Onde ele está em relação a pessoas da mesma idade e sexo, usando percentil, age grade e idade fitness do dossiê — respeitando a regra de esforco_maximo.'),

                    'nutricao_pre_treino' => $texto('O que comer antes de uma sessão como esta, e quanto tempo antes.'),
                    'nutricao_durante' => $texto('Necessidade de carboidrato ou eletrólito durante, dada a duração.'),
                    'nutricao_pos_treino' => $texto('Recuperação: proteína e carboidrato, com quantidade e janela de tempo.'),
                    'nutricao_hidratacao' => $texto('Quanto beber, considerando duração, suor estimado e peso corporal.'),

                    'proximo_treino_tipo' => $texto('Tipo do próximo treino: regenerativo, longo, intervalado, tempo run ou descanso.'),
                    'proximo_treino_descricao' => $texto('A sessão prescrita, com distância, ritmo alvo e zona de FC.'),
                    'proximo_treino_quando' => $texto('Em quantos dias, considerando a recuperação atual.'),

                    'meta_curto_prazo' => $texto('Um objetivo mensurável para as próximas 4 semanas.'),
                ],
                'required' => [
                    'nota_geral', 'resumo', 'pacing_comentario',
                    'esforco_zona_predominante', 'esforco_deriva_cardiaca', 'esforco_comentario',
                    'pontos_fortes', 'pontos_de_atencao', 'comparativo',
                    'nutricao_pre_treino', 'nutricao_durante', 'nutricao_pos_treino', 'nutricao_hidratacao',
                    'proximo_treino_tipo', 'proximo_treino_descricao', 'proximo_treino_quando',
                    'meta_curto_prazo',
                ],
            ],
        ];
    }

    /**
     * Trava o que o schema não garante: faixa da nota e tamanho das listas.
     *
     * O veredito de pacing nunca vem da IA — é fato calculado a partir dos
     * splits, e o que a IA respondeu é descartado. Sem splits ninguém pode
     * afirmar como o ritmo se distribuiu, nem ela: fica 'indefinido'.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $contexto
     * @return array<string, mixed>
     */
    private function normalizar(array $payload, array $contexto): array
    {
        $nota = (int) ($payload['nota_geral'] ?? 0);

        return [
            'nota_geral' => max(0, min(10, $nota)),
            'resumo' => $this->str($payload['resumo'] ?? null),
            'pacing' => [
                'veredito' => $contexto['splits']['veredito'] ?? 'indefinido',
                'comentario' => $this->str($payload['pacing_comentario'] ?? null),
            ],
            'esforco' => [
                'zona_predominante' => $this->str($payload['esforco_zona_predominante'] ?? null),
                'deriva_cardiaca' => $this->str($payload['esforco_deriva_cardiaca'] ?? null),
                'comentario' => $this->str($payload['esforco_comentario'] ?? null),
            ],
            'pontos_fortes' => $this->lista($payload['pontos_fortes'] ?? null),
            'pontos_de_atencao' => $this->lista($payload['pontos_de_atencao'] ?? null),
            'comparativo' => $this->str($payload['comparativo'] ?? null),
            'nutricao' => [
                'pre_treino' => $this->str($payload['nutricao_pre_treino'] ?? null),
                'durante' => $this->str($payload['nutricao_durante'] ?? null),
                'pos_treino' => $this->str($payload['nutricao_pos_treino'] ?? null),
                'hidratacao' => $this->str($payload['nutricao_hidratacao'] ?? null),
            ],
            'proximo_treino' => [
                'tipo' => $this->str($payload['proximo_treino_tipo'] ?? null),
                'descricao' => $this->str($payload['proximo_treino_descricao'] ?? null),
                'quando' => $this->str($payload['proximo_treino_quando'] ?? null),
            ],
            'meta_curto_prazo' => $this->str($payload['meta_curto_prazo'] ?? null),
        ];
    }

    private function str(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }

    /** @return list<string> */
    private function lista(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $itens = array_values(array_filter(
            array_map(fn ($item) => $this->str($item), $valor),
            fn (string $item) => $item !== '',
        ));

        return array_slice($itens, 0, self::MAX_ITENS_LISTA);
    }

    /**
     * @param  list<array<string, mixed>>  $contentBlocks
     * @param  array<string, mixed>  $tool
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
                'model' => config('saude.cardio.model'),
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
