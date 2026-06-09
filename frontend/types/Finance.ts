export type FinanceCategoryKind = "despesa" | "receita";

export interface FinanceCategory {
  id: number;
  name: string;
  kind: FinanceCategoryKind;
  color: string;
}

export interface FinanceCategoryPayload {
  name: string;
  kind: FinanceCategoryKind;
  color: string;
}

export interface BankAccount {
  id: number;
  name: string;
  balance: string;
}

export interface BankAccountsResponse {
  accounts: BankAccount[];
  total: number;
}

export interface BankAccountPayload {
  name: string;
  balance: number;
}

export type PayableKind = "avulsa" | "fixa" | "parcelada";

export interface Payable {
  id: number;
  category_id: number | null;
  description: string;
  amount: string;
  due_date: string;
  is_paid: boolean;
  paid_at: string | null;
  bank_account_id: number | null;
  kind: PayableKind;
  installment_number: number | null;
  installments_total: number | null;
  group_id: string | null;
  category?: FinanceCategory | null;
  bank_account?: { id: number; name: string } | null;
}

export interface PayablePayload {
  description: string;
  category_id: number | null;
  amount: number;
  due_date: string;
  kind: PayableKind;
  installments_total?: number;
}

export type ReceivableKind = "avulsa" | "fixa";

export interface Receivable {
  id: number;
  category_id: number | null;
  description: string;
  amount: string;
  due_date: string;
  is_received: boolean;
  received_at: string | null;
  bank_account_id: number | null;
  kind: ReceivableKind;
  group_id: string | null;
  category?: FinanceCategory | null;
  bank_account?: { id: number; name: string } | null;
}

export interface ReceivablePayload {
  description: string;
  category_id: number | null;
  amount: number;
  due_date: string;
  kind: ReceivableKind;
}

export type AcertoDirection = "pagar" | "receber";

export interface AcertoSettlement {
  id: number;
  bank_account_id: number | null;
  amount: string;
  settled_at: string;
  bank_account?: { id: number; name: string } | null;
}

export interface Acerto {
  id: number;
  direction: AcertoDirection;
  category_id: number | null;
  description: string;
  amount: string;
  notes: string | null;
  is_settled: boolean;
  settled_amount: number;
  remaining: number;
  category?: FinanceCategory | null;
  settlements?: AcertoSettlement[];
}

export interface AcertoPayload {
  direction: AcertoDirection;
  category_id: number | null;
  description: string;
  amount: number;
  notes?: string | null;
}

export interface FinanceSummary {
  month: string;
  accounts_total: number;
  payables: { total: number; paid: number; pending: number };
  receivables: { total: number; received: number; pending: number };
  balance_month: number;
  by_category: Array<{ name: string; color: string; total: number }>;
}

export interface FinanceReportMonthly {
  month: string;
  income: number;
  expense: number;
  balance: number;
}

export interface FinanceReportCategoryMonthly {
  name: string;
  color: string;
  monthly: Record<string, number>;
  total: number;
}

export interface FinanceReportCashflowCategory {
  name: string;
  color: string;
  kind: FinanceCategoryKind;
  total: number;
}

export interface FinanceReport {
  start: string;
  end: string;
  months: string[];
  monthly: FinanceReportMonthly[];
  expense_by_category: FinanceReportCategoryMonthly[];
  cashflow_by_category: FinanceReportCashflowCategory[];
}
