"use client";

import { Eye, EyeOff } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { usePrivacy } from "@/context/PrivacyContext";

/**
 * Botão único do cabeçalho que revela/esconde todos os valores em dinheiro do
 * painel. Oculto mostra o olho aberto (o convite: "clique para ver").
 */
export function PrivacyToggle() {
  const { hidden, toggle } = usePrivacy();
  const label = hidden ? "Mostrar valores financeiros" : "Ocultar valores financeiros";

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          onClick={toggle}
          aria-pressed={hidden}
          aria-label={label}
        >
          {hidden ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
        </Button>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  );
}
