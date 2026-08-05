"use client";

import { cn } from "@/lib/utils";
import { usePrivacy } from "@/context/PrivacyContext";

/**
 * Envolve um gráfico para o modo privado.
 *
 * Mascarar os formatadores apaga tooltips e rótulos de eixo, mas não a forma:
 * altura de barra, ângulo de fatia e curva de área continuam entregando as
 * proporções. Daí o desfoque por cima.
 *
 * Todo gráfico do painel é `<ResponsiveContainer height="100%">` dentro de um
 * `CardContent` de altura fixa, então os divs daqui precisam de `h-full` — sem
 * isso o gráfico colapsa para altura zero. Quando visível não há wrapper nenhum,
 * e o layout fica idêntico ao de antes.
 */
export function PrivateChart({
  children,
  className,
}: {
  children: React.ReactNode;
  className?: string;
}) {
  const { hidden } = usePrivacy();

  if (!hidden) {
    return <>{children}</>;
  }

  return (
    <div className={cn("relative h-full", className)}>
      <div aria-hidden className="h-full select-none blur-md pointer-events-none">
        {children}
      </div>
      <div className="absolute inset-0 flex items-center justify-center">
        <span className="rounded-full border bg-background/80 px-3 py-1 text-xs text-muted-foreground">
          Valores ocultos
        </span>
      </div>
    </div>
  );
}
