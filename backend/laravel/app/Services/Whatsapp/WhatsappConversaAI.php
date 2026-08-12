<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappConversa;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Interpreta a SUA resposta a uma proposta pendente do assistente.
 *
 * Só é chamado quando o parse determinístico (sim/não/número, ver
 * WhatsappRespostaParser) não resolveu — ou seja, quando a resposta traz algo
 * além do aceite: "na verdade é urgente", "muda pra 45 reais", "Sim, lançar do
 * Itaú". Mesmo padrão das demais integrações (tool use forçado, single-turn).
 */
class WhatsappConversaAI
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const ACOES = ['confirmar', 'corrigir', 'cancelar', 'nova_mensagem', 'nao_entendi'];

    private const PRIORIDADES = ['low', 'medium', 'high', 'urgent'];

    private const TIPOS_REFEICAO = ['cafe_da_manha', 'almoco', 'jantar', 'lanche', 'outro'];

    /**
     * @param  array<string, mixed>  $proposta  o que está pendente (payload do estado)
     * @param  list<array{chave: string, label: string}>  $destinos  contas/cartões, só no fluxo comprovante
     * @param  list<array{id: int, name: string}>  $categorias  categorias de despesa, só no fluxo comprovante
     * @return array{acao: string, destino: string|null, correcoes: array<string, mixed>|null, resumo_correcao: string|null}
     */
    public function interpretarResposta(
        string $resposta,
        string $fluxo,
        array $proposta,
        array $destinos = [],
        array $categorias = [],
    ): array {
        try {
            $payload = $this->chamarClaude(
                $this->prompt($fluxo),
                $this->contexto($resposta, $proposta, $destinos, $categorias),
                $this->tool($fluxo, $destinos, $categorias),
            );
        } catch (\Throwable) {
            // Nunca commita no escuro: quem chamou reapresenta com menu numerado.
            return $this->vazio('nao_entendi');
        }

        return $this->normalizar($payload, $fluxo, $destinos, $categorias);
    }

    private function prompt(string $fluxo): string
    {
        $oQueE = match ($fluxo) {
            WhatsappConversa::FLUXO_TAREFA => 'uma tarefa que ele quer criar no kanban',
            WhatsappConversa::FLUXO_REFEICAO => 'uma refeição que ele quer registrar no diário alimentar',
            WhatsappConversa::FLUXO_COMPROVANTE => 'um comprovante de pagamento que ele quer lançar no financeiro',
            default => 'um registro que ele quer criar',
        };

        $extraDestino = $fluxo === WhatsappConversa::FLUXO_COMPROVANTE
            ? "\n- Se ele indicar onde lançar (\"lançar do Itaú\", \"põe no cartão Nubank\"), preencha destino com a chave EXATA da lista. Indicar o destino junto com um aceite é 'confirmar', não 'corrigir'."
            : '';

        return <<<TXT
O assistente do Lucas propôs {$oQueE} e perguntou se está correto. Interprete a resposta dele e chame a ferramenta interpretar_resposta.

Escolha a acao:
- 'confirmar': ele aceitou como está ("sim", "isso", "pode lançar", "manda ver").
- 'corrigir': ele aceitou o registro mas mudou algum dado ("na verdade é urgente", "muda pra 45 reais", "foi jantar, não almoço"). Preencha SÓ os campos que ele mudou em correcoes; o resto fica como está na proposta.
- 'cancelar': ele desistiu ("não", "deixa pra lá", "esquece", "errado").
- 'nova_mensagem': ele ignorou a pergunta e falou de outro assunto — é um novo registro, não uma resposta a este.
- 'nao_entendi': ambíguo demais para decidir com segurança. Prefira esta a arriscar.{$extraDestino}

Regras:
- Na dúvida entre 'corrigir' e 'nao_entendi', escolha 'nao_entendi'. Um dado errado gravado é pior que uma pergunta a mais.
- resumo_correcao: uma frase curta em português do Brasil dizendo o que mudou, ou null.
TXT;
    }

    /**
     * @param  array<string, mixed>  $proposta
     * @param  list<array{chave: string, label: string}>  $destinos
     * @param  list<array{id: int, name: string}>  $categorias
     */
    private function contexto(string $resposta, array $proposta, array $destinos, array $categorias): string
    {
        $partes = ['Proposta pendente:', json_encode($proposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)];

        if ($destinos !== []) {
            $lista = implode("\n", array_map(fn ($d) => "{$d['chave']}: {$d['label']}", $destinos));
            $partes[] = "\nDestinos possíveis (chave: nome):\n{$lista}";
        }

        if ($categorias !== []) {
            $lista = implode("\n", array_map(fn ($c) => "{$c['id']}: {$c['name']}", $categorias));
            $partes[] = "\nCategorias de despesa (id: nome):\n{$lista}";
        }

        $partes[] = "\nResposta do Lucas: {$resposta}";

        return implode("\n", $partes);
    }

    /**
     * @param  list<array{chave: string, label: string}>  $destinos
     * @param  list<array{id: int, name: string}>  $categorias
     * @return array<string, mixed>
     */
    private function tool(string $fluxo, array $destinos, array $categorias): array
    {
        $propriedades = [
            'acao' => ['type' => 'string', 'enum' => self::ACOES],
            'correcoes' => [
                'type' => ['object', 'null'],
                'properties' => $this->camposDoFluxo($fluxo, $categorias),
            ],
            'resumo_correcao' => ['type' => ['string', 'null']],
        ];

        if ($destinos !== []) {
            // Chave composta ('conta:12' / 'cartao:7'): com id e tipo separados,
            // a IA pode combinar tipo de um com id de outro.
            $propriedades['destino'] = [
                'type' => ['string', 'null'],
                'enum' => [...array_column($destinos, 'chave'), null],
                'description' => 'Onde lançar, quando ele indicar. Null se não mencionou.',
            ];
        }

        return [
            'name' => 'interpretar_resposta',
            'description' => 'Registra como interpretar a resposta do usuário a uma proposta pendente do assistente.',
            'input_schema' => [
                'type' => 'object',
                'properties' => $propriedades,
                'required' => ['acao'],
            ],
        ];
    }

    /**
     * Só os campos do fluxo em questão entram no schema — um schema com campos
     * de outro domínio convida a IA a preencher o que não existe.
     *
     * @param  list<array{id: int, name: string}>  $categorias
     * @return array<string, mixed>
     */
    private function camposDoFluxo(string $fluxo, array $categorias): array
    {
        return match ($fluxo) {
            WhatsappConversa::FLUXO_TAREFA => [
                'titulo' => ['type' => ['string', 'null']],
                'descricao' => ['type' => ['string', 'null']],
                'prioridade' => ['type' => ['string', 'null'], 'enum' => [...self::PRIORIDADES, null]],
                'due_date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            ],
            WhatsappConversa::FLUXO_REFEICAO => [
                'nome' => ['type' => ['string', 'null']],
                'tipo' => ['type' => ['string', 'null'], 'enum' => [...self::TIPOS_REFEICAO, null]],
                'calorias' => ['type' => ['integer', 'null']],
                'proteinas_g' => ['type' => ['number', 'null']],
            ],
            WhatsappConversa::FLUXO_COMPROVANTE => [
                'descricao' => ['type' => ['string', 'null']],
                'valor' => ['type' => ['number', 'null']],
                'data' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
                'categoria_id' => [
                    'type' => ['integer', 'null'],
                    'enum' => [...array_column($categorias, 'id'), null],
                ],
                'parcelas' => ['type' => ['integer', 'null'], 'description' => 'Total de parcelas, se for compra parcelada'],
            ],
            default => [],
        };
    }

    // ============================================================
    // Normalização defensiva
    // ============================================================

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{chave: string, label: string}>  $destinos
     * @param  list<array{id: int, name: string}>  $categorias
     * @return array{acao: string, destino: string|null, correcoes: array<string, mixed>|null, resumo_correcao: string|null}
     */
    private function normalizar(array $payload, string $fluxo, array $destinos, array $categorias): array
    {
        $acao = (string) ($payload['acao'] ?? 'nao_entendi');
        if (! in_array($acao, self::ACOES, true)) {
            $acao = 'nao_entendi';
        }

        $destino = $payload['destino'] ?? null;
        if (! is_string($destino) || ! in_array($destino, array_column($destinos, 'chave'), true)) {
            $destino = null;
        }

        $correcoes = is_array($payload['correcoes'] ?? null)
            ? $this->normalizarCorrecoes($payload['correcoes'], $fluxo, $categorias)
            : null;

        // "Corrigir" sem nenhuma correção utilizável é só um aceite disfarçado.
        if ($acao === 'corrigir' && $correcoes === null) {
            $acao = $destino !== null ? 'confirmar' : 'nao_entendi';
        }

        $resumo = $payload['resumo_correcao'] ?? null;

        return [
            'acao' => $acao,
            'destino' => $destino,
            'correcoes' => $correcoes,
            'resumo_correcao' => is_string($resumo) && trim($resumo) !== '' ? trim($resumo) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $correcoes
     * @param  list<array{id: int, name: string}>  $categorias
     * @return array<string, mixed>|null
     */
    private function normalizarCorrecoes(array $correcoes, string $fluxo, array $categorias): ?array
    {
        $limpo = [];

        foreach ($this->camposDoFluxo($fluxo, $categorias) as $campo => $_) {
            if (! array_key_exists($campo, $correcoes) || $correcoes[$campo] === null) {
                continue;
            }
            $valor = $this->normalizarCampo($campo, $correcoes[$campo], $categorias);
            if ($valor !== null) {
                $limpo[$campo] = $valor;
            }
        }

        return $limpo !== [] ? $limpo : null;
    }

    /**
     * @param  list<array{id: int, name: string}>  $categorias
     */
    private function normalizarCampo(string $campo, mixed $valor, array $categorias): mixed
    {
        return match ($campo) {
            'titulo', 'nome' => $this->texto($valor, 120),
            'descricao' => $this->texto($valor, 255),
            'prioridade' => in_array($valor, self::PRIORIDADES, true) ? $valor : null,
            'tipo' => in_array($valor, self::TIPOS_REFEICAO, true) ? $valor : null,
            'due_date', 'data' => is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null,
            'calorias' => is_numeric($valor) ? max(0, min(5000, (int) round((float) $valor))) : null,
            'proteinas_g' => is_numeric($valor) ? round(max(0, min(500, (float) $valor)), 1) : null,
            'valor' => is_numeric($valor) && (float) $valor > 0 ? round((float) $valor, 2) : null,
            'categoria_id' => in_array((int) $valor, array_column($categorias, 'id'), true) ? (int) $valor : null,
            'parcelas' => is_numeric($valor) && (int) $valor >= 2 && (int) $valor <= 360 ? (int) $valor : null,
            default => null,
        };
    }

    private function texto(mixed $valor, int $limite): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        return mb_substr(trim($valor), 0, $limite);
    }

    /**
     * @return array{acao: string, destino: null, correcoes: null, resumo_correcao: null}
     */
    private function vazio(string $acao): array
    {
        return ['acao' => $acao, 'destino' => null, 'correcoes' => null, 'resumo_correcao' => null];
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    private function chamarClaude(string $systemPrompt, string $texto, array $tool): array
    {
        $key = config('services.anthropic.key');
        if (empty($key)) {
            throw new RuntimeException('A interpretação por IA não está configurada. Defina ANTHROPIC_API_KEY no .env do backend.');
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => config('services.anthropic.version'),
                'content-type' => 'application/json',
            ])->timeout(60)->retry(2, 500, throw: false)->post(self::ENDPOINT, [
                'model' => config('whatsapp.ia.model'),
                'max_tokens' => 2048,
                'system' => $systemPrompt,
                'tools' => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => 'interpretar_resposta'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => $texto]],
                ]],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Não foi possível contatar o serviço de IA.', 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('A IA recusou a interpretação ('.($response->json('error.message') ?? 'erro desconhecido').').');
        }

        foreach ($response->json('content') ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_array($block['input'] ?? null)) {
                return $block['input'];
            }
        }

        throw new RuntimeException('A IA não retornou a interpretação estruturada.');
    }
}
