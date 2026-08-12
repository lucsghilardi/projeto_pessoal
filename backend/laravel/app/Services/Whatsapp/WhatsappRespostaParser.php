<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Str;

/**
 * Leitura determinística das suas respostas — sem IA, sem latência, sem custo.
 *
 * Cobre a esmagadora maioria dos turnos ("sim", "2", "cancela"). Só o que sobra
 * daqui vai para o WhatsappConversaAI, que é onde moram as respostas com
 * conteúdo extra ("Sim, lançar do Itaú", "na verdade é urgente").
 */
class WhatsappRespostaParser
{
    private const SIM = [
        'sim', 's', 'ss', 'si', 'ok', 'okay', 'okey', 'isso', 'confirma', 'confirmar', 'confirmado',
        'certo', 'correto', 'exato', 'pode', 'pode ser', 'positivo', 'bora', 'manda', 'manda ver',
        'vai', 'perfeito', 'beleza', 'blz', 'show', 'yes', 'y', 'uhum', 'aham', 'ta', 'tá',
    ];

    private const NAO = [
        'nao', 'n', 'nop', 'nada', 'cancela', 'cancelar', 'cancelado', 'deixa', 'deixa pra la',
        'esquece', 'errado', 'negativo', 'para', 'no', 'descarta', 'descartar', 'apaga', 'apagar',
    ];

    /**
     * Emojis de aceite/recusa que chegam sozinhos. Nenhum deles pode ser um
     * prefixo do bot (config whatsapp.conversa.prefixos_bot) — por isso ✅ e ❌
     * ficam de fora: o bot abre mensagens com eles, e o cinto de anti-loop no
     * ingest descartaria a sua resposta junto com o eco.
     */
    private const EMOJI_SIM = ['👍', '👌', '🆗', '💪', '🙌'];

    private const EMOJI_NAO = ['👎', '🚫', '🙅'];

    /**
     * Normalização compartilhada: sem acento, minúsculo, só letras/números.
     */
    public static function normalizar(string $texto): string
    {
        return (string) Str::of($texto)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9 ]+/', ' ')
            ->squish();
    }

    /**
     * Aceite/recusa isolado. Null quando a resposta traz mais que isso — aí é
     * caso de IA (pode conter uma correção ou o destino do lançamento).
     */
    public static function simOuNao(string $texto): ?bool
    {
        $bruto = trim($texto);

        if (in_array($bruto, self::EMOJI_SIM, true)) {
            return true;
        }
        if (in_array($bruto, self::EMOJI_NAO, true)) {
            return false;
        }

        $n = self::normalizar($texto);
        if ($n === '') {
            return null;
        }

        if (in_array($n, self::SIM, true)) {
            return true;
        }
        if (in_array($n, self::NAO, true)) {
            return false;
        }

        return null;
    }

    /**
     * Escolha de um menu numerado: "2", "2️⃣", "opcao 2". Devolve o índice
     * 1-based, ou null. `$total` limita ao que foi realmente oferecido.
     */
    public static function numero(string $texto, int $total): ?int
    {
        $n = self::normalizar(self::desemojiNumeros($texto));

        if (! preg_match('/^(?:opcao |op |numero |n )?(\d{1,2})$/', $n, $m)) {
            return null;
        }

        $escolha = (int) $m[1];

        return ($escolha >= 1 && $escolha <= $total) ? $escolha : null;
    }

    /**
     * Casa a resposta com uma das opções oferecidas, por número ou por nome.
     * As opções vêm CONGELADAS do payload do estado — reconstruí-las entre um
     * turno e outro faria o "2" apontar para outra conta.
     *
     * @param  list<array{chave: string, label: string}>  $opcoes
     * @return string|null a chave da opção escolhida
     */
    public static function escolherOpcao(string $texto, array $opcoes): ?string
    {
        if ($opcoes === []) {
            return null;
        }

        $indice = self::numero($texto, count($opcoes));
        if ($indice !== null) {
            return $opcoes[$indice - 1]['chave'];
        }

        $n = self::normalizar($texto);
        if ($n === '') {
            return null;
        }

        $achados = [];
        foreach ($opcoes as $opcao) {
            $label = self::normalizar($opcao['label']);
            if ($label === '') {
                continue;
            }
            if ($label === $n || str_contains($n, $label) || str_contains($label, $n)) {
                $achados[] = $opcao['chave'];
            }
        }

        // Ambíguo é o mesmo que não reconhecido: melhor perguntar de novo.
        return count($achados) === 1 ? $achados[0] : null;
    }

    /**
     * "sim"/"não" isolado sem nada pendente não é um registro — é uma resposta
     * perdida. Sem isso, um "sim" atrasado viraria uma tarefa chamada "sim".
     */
    public static function ehRespostaSolta(string $texto): bool
    {
        return self::simOuNao($texto) !== null;
    }

    /**
     * Converte os emojis de teclado numérico (1️⃣) no dígito correspondente,
     * porque o ascii() do Str os descarta inteiros.
     */
    private static function desemojiNumeros(string $texto): string
    {
        return strtr($texto, [
            '0️⃣' => '0', '1️⃣' => '1', '2️⃣' => '2', '3️⃣' => '3', '4️⃣' => '4',
            '5️⃣' => '5', '6️⃣' => '6', '7️⃣' => '7', '8️⃣' => '8', '9️⃣' => '9',
        ]);
    }
}
