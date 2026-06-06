export type ReceiptPaymentMethod = "credito" | "debito" | "pix" | "desconhecido";

export type ReceiptConfidence = "alta" | "media" | "baixa";

export type AiReceiptDestination = "cartao" | "conta";

export type ReceiptDocumentType = "comprovante" | "fatura" | "extrato";

export interface ReceiptItem {
  amount: number | null;
  purchase_date: string | null;
  description: string | null;
  payment_method: ReceiptPaymentMethod;
  installments_total: number | null;
  category_id: number | null;
  confidence: ReceiptConfidence;
  duplicate: boolean;
}

export interface ReceiptParseResponse {
  receipt_path: string;
  document_type: ReceiptDocumentType;
  card_last_four: string | null;
  suggested_card_id: number | null;
  items: ReceiptItem[];
  suggestion: {
    destination: AiReceiptDestination;
  };
}

export interface AiReceiptConfirmPayload {
  destination: AiReceiptDestination;
  receipt_path: string;
  description: string;
  amount: number;
  date: string;
  category_id: number | null;
  credit_card_id?: number;
  installments_total?: number;
  reference_month?: string;
  bank_account_id?: number;
}

export interface AiReceiptBatchItem {
  description: string;
  amount: number;
  date: string;
  category_id: number | null;
}

export interface AiReceiptBatchPayload {
  destination: AiReceiptDestination;
  receipt_path: string;
  credit_card_id?: number;
  bank_account_id?: number;
  items: AiReceiptBatchItem[];
}

export interface AiReceiptBatchResult {
  message: string;
  created: number;
  skipped: number;
}

export interface AiReceiptCheckDuplicatesPayload {
  destination: AiReceiptDestination;
  credit_card_id?: number;
  bank_account_id?: number;
  items: Array<{ description: string; amount: number; date: string }>;
}

export interface AiReceiptCheckDuplicatesResult {
  duplicates: boolean[];
}
