<?php

namespace App\Services\Finance;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\User;
use App\Services\Whatsapp\WhatsappRespostaParser;

/**
 * Descobre onde lançar um comprovante: conta bancária (vira conta a pagar já
 * paga, debitando o saldo) ou cartão de crédito (vira transação na fatura).
 *
 * A chave é composta ('conta:12' / 'cartao:7') para que tipo e id nunca se
 * separem no caminho de volta pela IA ou pelo payload do estado.
 */
class FinanceDestinoResolver
{
    /**
     * Todas as opções do usuário, na ordem em que aparecem no menu.
     *
     * @return list<array{chave: string, label: string, tipo: string, id: int}>
     */
    public function opcoes(User $user): array
    {
        $contas = BankAccount::where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => [
                'chave' => "conta:{$c->id}",
                'label' => $c->name,
                'tipo' => 'conta',
                'id' => (int) $c->id,
            ]);

        $cartoes = CreditCard::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => [
                'chave' => "cartao:{$c->id}",
                // "cartão X" no label desambigua o banco que também é cartão
                // (Nubank conta vs Nubank cartão) tanto para você quanto para a IA.
                'label' => "cartão {$c->name}",
                'tipo' => 'cartao',
                'id' => (int) $c->id,
            ]);

        return $contas->concat($cartoes)->values()->all();
    }

    /**
     * Melhor palpite antes de perguntar qualquer coisa:
     *   1. os 4 últimos dígitos batem com um cartão cadastrado;
     *   2. crédito → cartão, pix/débito → conta; se só houver um candidato, é ele;
     * Devolve null quando há ambiguidade real — aí o assistente pergunta.
     *
     * @param  list<array{chave: string, label: string, tipo: string, id: int}>  $opcoes
     * @return array{chave: string, label: string, tipo: string, id: int}|null
     */
    public function sugerir(array $opcoes, ?string $cardLastFour, string $paymentMethod, User $user): ?array
    {
        if ($cardLastFour !== null && $cardLastFour !== '') {
            $ids = CreditCard::where('user_id', $user->id)
                ->where('is_active', true)
                ->where('last_four', $cardLastFour)
                ->pluck('id');

            if ($ids->count() === 1) {
                $achado = $this->porChave($opcoes, "cartao:{$ids->first()}");
                if ($achado !== null) {
                    return $achado;
                }
            }
        }

        $tipoProvavel = $paymentMethod === 'credito' ? 'cartao' : 'conta';
        $candidatos = array_values(array_filter($opcoes, fn ($o) => $o['tipo'] === $tipoProvavel));

        return count($candidatos) === 1 ? $candidatos[0] : null;
    }

    /**
     * Resolve o que você escreveu ("Itaú", "cartão Nubank", "2") contra as
     * opções congeladas. Ambíguo devolve null — perguntar de novo é barato.
     *
     * @param  list<array{chave: string, label: string, tipo: string, id: int}>  $opcoes
     * @return array{chave: string, label: string, tipo: string, id: int}|null
     */
    public function resolverTexto(array $opcoes, string $texto): ?array
    {
        $chave = WhatsappRespostaParser::escolherOpcao(
            $texto,
            array_map(fn ($o) => ['chave' => $o['chave'], 'label' => $o['label']], $opcoes),
        );

        return $chave !== null ? $this->porChave($opcoes, $chave) : null;
    }

    /**
     * @param  list<array{chave: string, label: string, tipo: string, id: int}>  $opcoes
     * @return array{chave: string, label: string, tipo: string, id: int}|null
     */
    public function porChave(array $opcoes, ?string $chave): ?array
    {
        if ($chave === null) {
            return null;
        }

        foreach ($opcoes as $opcao) {
            if ($opcao['chave'] === $chave) {
                return $opcao;
            }
        }

        return null;
    }
}
