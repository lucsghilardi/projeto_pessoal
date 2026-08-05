"use client";

// Formatadores cientes do modo privado.
//
// A ideia: como toda tela já escreve `formatCurrency(x)` e recebe uma string de
// volta, desestruturar o formatador daqui *sombreia* o import de `@/lib/format`
// e nenhum ponto de uso precisa mudar:
//
//   - import { formatCurrency, toNumber } from "@/lib/format";
//   + import { toNumber } from "@/lib/format";
//   + import { usePrivateFormat } from "@/hooks/use-private-format";
//     export default function MinhaPagina() {
//   +   const { formatCurrency } = usePrivateFormat();
//
// Isso também vale para os pontos que não aceitam JSX (props string, template
// literals, e os callbacks `tickFormatter`/`formatter` do recharts), que são
// cerca de um terço do total.
//
// Mascarar aqui, e não via CSS, faz os dígitos nunca entrarem no DOM: copiar a
// página não leva valor junto. Mas atenção — os números seguem no state do
// React e nas respostas da API. Isto protege contra olhar por cima do ombro e
// compartilhar tela; não é uma fronteira de segurança.

import { useMemo } from "react";
import {
  compactCurrency as compactCurrencyRaw,
  formatCurrency as formatCurrencyRaw,
  formatPercent as formatPercentRaw,
} from "@/lib/format";
import { usePrivacy } from "@/context/PrivacyContext";

export const PRIVATE_MASK = "••••";

export function usePrivateFormat() {
  const { hidden } = usePrivacy();

  // Memoizado por `hidden` para o recharts não ver uma prop nova a cada render.
  return useMemo(() => {
    if (!hidden) {
      return {
        hidden,
        formatCurrency: formatCurrencyRaw,
        compactCurrency: compactCurrencyRaw,
        formatPercent: formatPercentRaw,
        accent: (className?: string) => className,
        maskInput: "",
      };
    }

    return {
      hidden,
      // `Parameters<...>` mantém a assinatura idêntica à do formatador real sem
      // declarar um parâmetro que não é lido. Preserva o símbolo da moeda, para
      // a linha continuar legível como "dinheiro".
      formatCurrency: (...args: Parameters<typeof formatCurrencyRaw>) =>
        `${args[1] === "USD" ? "US$" : "R$"} ${PRIVATE_MASK}`,
      // Só usado como `tickFormatter` de YAxis: string vazia apaga os rótulos do
      // eixo sem mexer no `width`, então a área do gráfico não se desloca.
      compactCurrency: () => "",
      formatPercent: () => `${PRIVATE_MASK}%`,
      // Verde/vermelho entregariam o sinal de um valor mascarado.
      accent: () => undefined,
      // Para os <Input> que já vêm preenchidos com saldo real: eles não passam
      // por formatCurrency, então precisam de desfoque. Volta ao normal no foco,
      // senão não dá para editar.
      maskInput: "blur-[3px] focus:blur-none",
    };
  }, [hidden]);
}
