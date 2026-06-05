export interface InvestmentInstitution {
  id: number;
  name: string;
  created_at?: string;
  updated_at?: string;
}

export interface InvestmentInstitutionPayload {
  name: string;
}
