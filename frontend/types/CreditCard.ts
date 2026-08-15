import type { FinanceCategory } from "@/types/Finance";

export interface CreditCard {
  id: number;
  name: string;
  brand: string | null;
  last_four: string | null;
  limit: string | null;
  closing_day: number;
  due_day: number;
  is_active: boolean;
  // Presentes apenas na listagem (index):
  outstanding?: number;
  available_limit?: number | null;
}

export interface CreditCardsResponse {
  credit_cards: CreditCard[];
}

export interface CreditCardPayload {
  name: string;
  brand: string | null;
  last_four: string | null;
  limit: number | null;
  closing_day: number;
  due_day: number;
  is_active?: boolean;
}

export interface CreditCardTransaction {
  id: number;
  credit_card_id: number;
  credit_card_invoice_id: number;
  category_id: number | null;
  description: string;
  amount: string;
  purchase_date: string;
  installment_number: number | null;
  installments_total: number | null;
  group_id: string | null;
  category?: FinanceCategory | null;
}

export interface CreditCardInvoicePayment {
  id: number;
  credit_card_invoice_id: number;
  bank_account_id: number | null;
  amount: string;
  paid_at: string;
  bank_account?: { id: number; name: string } | null;
}

export type CreditCardInvoiceStatus = "aberta" | "parcial" | "paga";

export interface CreditCardInvoice {
  id: number | null; // null = fatura ainda vazia (não persistida)
  credit_card_id: number;
  reference_month: string;
  closing_date: string;
  due_date: string;
  total: number;
  paid_total: number;
  remaining: number;
  status: CreditCardInvoiceStatus;
  transactions: CreditCardTransaction[];
  payments: CreditCardInvoicePayment[];
}

/**
 * Linha de previsão de fatura exibida em Contas a pagar (somente leitura).
 * Atenção: `is_closed` é o CICLO (já passou do corte) e `status` é o PAGAMENTO —
 * uma fatura pode estar com status "aberta" (nada pago) e o ciclo já fechado.
 */
export interface CreditCardInvoiceForecast {
  credit_card_id: number;
  invoice_id: number | null; // null = fatura ainda não persistida
  card_name: string;
  last_four: string | null;
  card_is_active: boolean;
  reference_month: string;
  closing_date: string;
  due_date: string;
  is_closed: boolean;
  total: number;
  paid_total: number;
  remaining: number;
  status: CreditCardInvoiceStatus;
}

export interface ResolvedInvoiceWindow {
  closing_date: string;
  due_date: string;
  reference_month: string;
}

export interface CreditCardTransactionPayload {
  credit_card_id: number;
  category_id: number | null;
  description: string;
  amount: number;
  purchase_date: string;
  installments_total?: number;
  // Quando true, "amount" é o valor total da compra (dividido entre as parcelas no backend).
  amount_is_total?: boolean;
  reference_month?: string;
}

export interface CreditCardTransactionUpdatePayload {
  // Permite mover o lançamento para outro cartão (lancei no cartão errado).
  credit_card_id?: number;
  category_id: number | null;
  description: string;
  amount: number;
  reference_month?: string;
}

export interface CreditCardInvoicePaymentPayload {
  bank_account_id: number;
  amount: number;
  paid_at?: string;
}
