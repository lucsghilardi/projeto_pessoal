export interface InvestmentTag {
  id: number;
  name: string;
  color: string;
  investments_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface InvestmentTagPayload {
  name: string;
  color: string;
}
