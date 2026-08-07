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

        const importadas = (resultado.cardio ?? 0) + (resultado.treinos ?? 0);
        if (importadas > 0) {
          appToast.success(
            `Garmin: ${importadas} atividade(s) importada(s).`,
          );
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
