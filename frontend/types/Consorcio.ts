import type { Payable } from "@/types/Finance";

export type ConsorcioTipo = "contemplado" | "novo";
export type ConsorcioStatus = "ativo" | "quitado" | "cancelado";

export interface Consorcio {
  id: number;
  nome: string;
  administradora: string | null;
  tipo: ConsorcioTipo;
  grupo: string | null;
  cota: string | null;
  valor_credito: string | null;
  valor_mensalidade: string | null;
  reducao_pct: string | null;
  prazo_meses: number | null;
  parcelas_pagas: number | null;
  valor_pago_inicial: string | null;
  dia_vencimento: number | null;
  data_contemplacao: string | null;
  status: ConsorcioStatus;
  proposta_path: string | null;
  observacoes: string | null;
  // Presentes apenas na listagem (index):
  parcelas_pagas_count?: number;
  total_pago?: number;
}

export interface ConsorciosResponse {
  consorcios: Consorcio[];
}

export interface ConsorcioPayload {
  nome: string;
  administradora: string | null;
  tipo: ConsorcioTipo;
  grupo: string | null;
  cota: string | null;
  valor_credito: number | null;
  valor_mensalidade: number | null;
  reducao_pct: number | null;
  prazo_meses: number | null;
  parcelas_pagas: number | null;
  valor_pago_inicial: number | null;
  dia_vencimento: number | null;
  data_contemplacao: string | null;
  status: ConsorcioStatus;
  observacoes: string | null;
}

// As parcelas do carnê são contas a pagar (Payable) vinculadas ao consórcio.
export interface ConsorcioParcelasResponse {
  parcelas: Payable[];
}

export interface ConsorcioParcelaPayload {
  numero: number | null;
  vencimento: string;
  valor: number;
}

export interface ConsorcioParcelaGeneratePayload {
  quantidade: number;
  valor: number;
  primeiro_vencimento: string;
  numero_inicial?: number;
}

export interface ConsorcioReajusteResponse {
  consorcio: Consorcio;
  parcelas: Payable[];
}
