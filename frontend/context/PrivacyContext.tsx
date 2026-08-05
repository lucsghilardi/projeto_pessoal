"use client";

// Modo privado: os valores em dinheiro do painel nascem mascarados e só aparecem
// quando o usuário clica no olho do cabeçalho.
//
// O estado é `useState(true)` puro — sem cookie, sem localStorage, sem efeito.
// Isso é intencional em dois sentidos: (1) recarregar a página sempre volta a
// ocultar, que é o ponto da funcionalidade; (2) servidor e cliente renderizam o
// mesmo valor inicial, então não há risco de hydration mismatch.

import { createContext, useContext, useMemo, useState } from "react";

interface PrivacyContextType {
  /** true = valores mascarados. Padrão em todo carregamento de página. */
  hidden: boolean;
  toggle: () => void;
  setHidden: (value: boolean) => void;
}

const PrivacyContext = createContext<PrivacyContextType | null>(null);

export function PrivacyProvider({ children }: { children: React.ReactNode }) {
  const [hidden, setHidden] = useState(true);

  const value = useMemo(
    () => ({ hidden, setHidden, toggle: () => setHidden((prev) => !prev) }),
    [hidden]
  );

  return (
    <PrivacyContext.Provider value={value}>
      {children}
    </PrivacyContext.Provider>
  );
}

export function usePrivacy() {
  const ctx = useContext(PrivacyContext);
  if (!ctx) {
    throw new Error("usePrivacy must be used inside PrivacyProvider");
  }
  return ctx;
}
