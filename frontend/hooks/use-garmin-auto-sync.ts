"use client";

import { useEffect, useRef } from "react";

import { appToast } from "@/lib/toast";
import { autoSincronizarSaudeGarmin } from "@/services/api";

/**
 * Dispara a sincronização do Garmin em segundo plano ao montar a página.
 * A trava de 10 min fica no servidor, então chamar de várias telas é barato.
 *
 * Silencioso por design: erro de integração nunca vira toast aqui — o job
 * horário e o botão manual continuam sendo os caminhos com feedback de erro.
 */
export function useGarminAutoSync(onImported: () => void | Promise<void>) {
  // Ref para não re-disparar o sync quando o callback muda de identidade.
  const onImportedRef = useRef(onImported);

  useEffect(() => {
    onImportedRef.current = onImported;
  }, [onImported]);

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const resultado = await autoSincronizarSaudeGarmin();

        if (!mounted || !resultado.executado) {
          return;
        }

        const atividades = (resultado.cardio ?? 0) + (resultado.treinos ?? 0);
        if (atividades > 0) {
          appToast.success(`Garmin: ${atividades} atividade(s) importada(s).`);
        }

        // Sono conta para o recarregamento mas não para o toast: ele não é
        // "atividade", e a tela de sono precisa se atualizar mesmo sem treino.
        if (atividades + (resultado.sono ?? 0) > 0) {
          await onImportedRef.current();
        }
      } catch {
        // Integração fora do ar não pode poluir a navegação.
      }
    })();

    return () => {
      mounted = false;
    };
  }, []);
}
