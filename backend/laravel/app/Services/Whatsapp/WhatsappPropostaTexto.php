<?php

namespace App\Services\Whatsapp;

use App\Models\SaudeRefeicao;
use App\Services\Saude\SaudeNutricaoService;
use Illuminate\Support\Carbon;

/**
 * Textos que o assistente manda no chat. Ficam juntos aqui porque a proposta e
 * a confirmação precisam ser reconhecíveis como o mesmo registro — e porque
 * todo texto daqui começa com um dos prefixos de `whatsapp.conversa.prefixos_bot`,
 * que é o cinto extra de anti-loop no ingest.
 */
class WhatsappPropostaTexto
{
    private const PRIORIDADES = [
        'low' => 'baixa',
        'medium' => 'média',
        'high' => 'alta',
        'urgent' => 'urgente',
    ];

    public function __construct(private SaudeNutricaoService $nutricao) {}

    /**
     * @param  array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}  $dados
     */
    public function propostaTarefa(array $dados, ?string $correcao = null): string
    {
        $linhas = ["📋 *Tarefa:* {$dados['titulo']}"];

        $detalhes = ['Prioridade '.(self::PRIORIDADES[$dados['prioridade']] ?? $dados['prioridade'])];
        if ($dados['due_date'] !== null) {
            $detalhes[] = 'prazo '.self::dataCurta($dados['due_date']);
        }
        $linhas[] = implode(' · ', $detalhes);

        if (($dados['descricao'] ?? null) !== null) {
            $linhas[] = '_'.$dados['descricao'].'_';
        }

        return $this->comPergunta($linhas, $correcao, 'Confirma? (sim / não / me diga o que corrigir)');
    }

    /**
     * @param  array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}  $dados
     */
    public function tarefaCriada(array $dados): string
    {
        $texto = "✅ Tarefa criada: {$dados['titulo']}";
        if ($dados['due_date'] !== null) {
            $texto .= ' (prazo '.self::dataCurta($dados['due_date']).')';
        }

        return $texto;
    }

    /**
     * Proposta da refeição: só o que foi entendido. O balanço do dia fica para
     * a confirmação, quando a refeição já entrou na conta.
     *
     * @param  array{nome: string, tipo: string, calorias: int, proteinas_g: float, carboidratos_g: float|null, gorduras_g: float|null, confianca: string}  $analise
     */
    public function propostaRefeicao(array $analise, ?string $correcao = null): string
    {
        $refeicao = new SaudeRefeicao($analise);

        $linhas = [$this->nutricao->textoRefeicao($refeicao, null, '🍽️')];

        return $this->comPergunta($linhas, $correcao, 'Confirma? (sim / não / me diga o que corrigir)');
    }

    /**
     * @param  array{consumido: array<string, mixed>, metas: array<string, mixed>, restante: array<string, mixed>}  $resumo
     */
    public function refeicaoRegistrada(SaudeRefeicao $refeicao, array $resumo): string
    {
        return $this->nutricao->textoRefeicao($refeicao, $resumo, '✅');
    }

    /**
     * @param  array{description: string, amount: float, date: string, category_nome: string|null, installments_total: int|null}  $lancamento
     */
    public function propostaComprovante(array $lancamento, ?string $destinoLabel, ?string $correcao = null): string
    {
        $linhas = ["🧾 *{$lancamento['description']}*"];

        $detalhes = [self::reais($lancamento['amount']), self::dataCurta($lancamento['date'])];
        if (($lancamento['category_nome'] ?? null) !== null) {
            $detalhes[] = $lancamento['category_nome'];
        }
        if (($lancamento['installments_total'] ?? null) !== null) {
            $detalhes[] = "{$lancamento['installments_total']}x";
        }
        $linhas[] = implode(' · ', $detalhes);

        if ($destinoLabel !== null) {
            $linhas[] = "Lançar em: *{$destinoLabel}*";

            return $this->comPergunta($linhas, $correcao, 'Confirma? (sim / não / diga outra conta)');
        }

        return $this->comPergunta($linhas, $correcao, 'Onde eu lanço? Ex.: "sim, lançar do Itaú"');
    }

    /**
     * @param  array{description: string, amount: float}  $lancamento
     */
    public function comprovanteLancado(array $lancamento, string $destinoLabel, int $parcelas): string
    {
        $texto = "✅ Lançado: {$lancamento['description']} · ".self::reais($lancamento['amount']);
        $texto .= $parcelas > 1 ? " em {$parcelas}x · {$destinoLabel}" : " · {$destinoLabel}";

        return $texto;
    }

    /**
     * Menu numerado. É a saída de emergência de qualquer estado: quando a
     * interpretação livre falha, cai aqui, que é sempre respondível sem IA.
     *
     * @param  list<array{chave: string, label: string}>  $opcoes
     */
    public function menu(string $pergunta, array $opcoes): string
    {
        $numeros = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣'];

        $linhas = [$pergunta];
        foreach (array_values($opcoes) as $i => $opcao) {
            $linhas[] = ($numeros[$i] ?? ($i + 1).'.').' '.$opcao['label'];
        }

        return implode("\n", $linhas);
    }

    /**
     * @param  list<string>  $linhas
     */
    private function comPergunta(array $linhas, ?string $correcao, string $pergunta): string
    {
        if ($correcao !== null) {
            array_unshift($linhas, "✏️ {$correcao}", '');
        }

        $linhas[] = '';
        $linhas[] = "_{$pergunta}_";

        return implode("\n", $linhas);
    }

    public static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    private static function dataCurta(string $data): string
    {
        return Carbon::parse($data)->format('d/m/Y');
    }
}
