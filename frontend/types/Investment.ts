import { InvestmentTag } from "./InvestmentTag";

export type InvestmentType =
  | "caixinha"
  | "poupanca"
  | "acoes"
  | "fii"
  | "fundo"
  | "tesouro"
  | "cdb"
  | "cripto"
  | "previdencia"
  | "outro";

export interface Investment {
  id: number;
  user_id: number;
  name: string;
  type: InvestmentType;
  institution: string | null;
  // Campos decimais chegam como string vinda do Laravel (decimal:2).
  applied_amount: string;
  current_amount: string;
  currency: string;
  notes: string | null;
  is_active: boolean;
  profit: number;
  profit_percent: number;
  contributed_total?: number | string | null;
  tags: InvestmentTag[];
  created_at?: string;
  updated_at?: string;
}

export interface InvestmentContribution {
  id: number;
  investment_id: number;
  amount: string;
  contributed_at: string;
}

export interface InvestmentPayload {
  name: string;
  type: InvestmentType;
  institution?: string | null;
  applied_amount: number;
  current_amount: number;
  currency?: string;
  notes?: string | null;
  is_active?: boolean;
  tag_ids: number[];
}

export interface InvestmentSummary {
  totals: {
    applied: number;
    current: number;
    profit: number;
    profit_percent: number;
    count: number;
  };
  by_type: Array<{
    type: InvestmentType;
    applied: number;
    current: number;
    count: number;
  }>;
  by_purpose: Array<{
    tag_id: number;
    name: string;
    color: string;
    applied: number;
    current: number;
    count: number;
  }>;
  evolution: Array<{
    date: string;
    applied: number;
    current: number;
  }>;
  usd_brl: number;
  usd_brl_available: boolean;
}
