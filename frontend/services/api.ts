import { ApiError, UnauthorizedError } from './apiError';
import { CreateUserPayload, UpdateUserPayload, User } from '@/types/User';
import { Investment, InvestmentPayload, InvestmentSummary } from '@/types/Investment';
import { InvestmentTag, InvestmentTagPayload } from '@/types/InvestmentTag';
import { InvestmentInstitution, InvestmentInstitutionPayload } from '@/types/InvestmentInstitution';
import { Asset, AssetPayload, AssetsResponse } from '@/types/Asset';
import { AiReceiptBatchPayload, AiReceiptBatchResult, AiReceiptConfirmPayload, ReceiptParseResponse } from '@/types/AiReceipt';
import {
    CreditCard,
    CreditCardInvoice,
    CreditCardInvoicePaymentPayload,
    CreditCardPayload,
    CreditCardsResponse,
    CreditCardTransaction,
    CreditCardTransactionPayload,
    CreditCardTransactionUpdatePayload,
    ResolvedInvoiceWindow,
} from '@/types/CreditCard';
import {
    BankAccount,
    BankAccountPayload,
    BankAccountsResponse,
    FinanceCategory,
    FinanceCategoryKind,
    FinanceCategoryPayload,
    FinanceSummary,
    Payable,
    PayablePayload,
    Receivable,
    ReceivablePayload,
} from '@/types/Finance';

const API_URL = '/api/proxy';

async function parseApiResponse<T>(res: Response): Promise<T> {
    if (!res.ok) {
        if (res.status === 401) {
            throw new UnauthorizedError();
        }

        const responseText = await res.text();
        let parsedBody: {
            message?: string;
            errors?: Record<string, string[]>;
        } | null = null;

        if (responseText) {
            try {
                parsedBody = JSON.parse(responseText) as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
            } catch {
                parsedBody = null;
            }
        }

        if (parsedBody) {
            const firstValidationError = parsedBody.errors
                ? Object.values(parsedBody.errors).flat()[0]
                : null;

            throw new ApiError(
                res.status,
                firstValidationError || parsedBody.message || 'Erro na API'
            );
        }

        throw new ApiError(res.status, responseText || 'Erro na API');
    }

    if (res.status === 204) {
        return null as T;
    }

    const text = await res.text();
    return text ? (JSON.parse(text) as T) : (null as T);
}

export async function apiFetch<T>(
    path: string,
    options: RequestInit = {}
): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

    if (!isFormData && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }

    const res = await fetch(`${API_URL}${path}`, {
        ...options,
        credentials: 'same-origin',
        headers,
    });

    return parseApiResponse<T>(res);
}

export async function login(
    email: string,
    password: string,
): Promise<void> {
    const res = await fetch('/api/auth/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ email, password }),
    });

    await parseApiResponse<{ authenticated: boolean }>(res);
}

export async function logout(): Promise<void> {
    const res = await fetch('/api/auth/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    await parseApiResponse<{ authenticated: boolean }>(res);
}

export function getUsers() {
    return apiFetch<User[]>('/users');
}

export function createUser(data: CreateUserPayload) {
    return apiFetch<User>('/users', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateUser(id: number, data: UpdateUserPayload) {
    return apiFetch<User>(`/users/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

// ===== Investimentos =====

export function getInvestments() {
    return apiFetch<Investment[]>('/investments');
}

export function getInvestmentSummary() {
    return apiFetch<InvestmentSummary>('/investments/summary');
}

export function createInvestment(data: InvestmentPayload) {
    return apiFetch<Investment>('/investments', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateInvestment(id: number, data: InvestmentPayload) {
    return apiFetch<Investment>(`/investments/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteInvestment(id: number) {
    return apiFetch<{ message: string }>(`/investments/${id}`, {
        method: 'DELETE',
    });
}

export function addContribution(
    id: number,
    data: { amount: number; contributed_at?: string },
) {
    return apiFetch<Investment>(`/investments/${id}/contributions`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function bulkUpdateInvestmentValues(
    items: Array<{ id: number; current_amount: number }>,
) {
    return apiFetch<Investment[]>('/investments/values', {
        method: 'PUT',
        body: JSON.stringify({ items }),
    });
}

export function getInvestmentTags() {
    return apiFetch<InvestmentTag[]>('/investment-tags');
}

export function createInvestmentTag(data: InvestmentTagPayload) {
    return apiFetch<InvestmentTag>('/investment-tags', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateInvestmentTag(id: number, data: InvestmentTagPayload) {
    return apiFetch<InvestmentTag>(`/investment-tags/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteInvestmentTag(id: number) {
    return apiFetch<{ message: string }>(`/investment-tags/${id}`, {
        method: 'DELETE',
    });
}

export function getInvestmentInstitutions() {
    return apiFetch<InvestmentInstitution[]>('/investment-institutions');
}

export function createInvestmentInstitution(data: InvestmentInstitutionPayload) {
    return apiFetch<InvestmentInstitution>('/investment-institutions', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateInvestmentInstitution(id: number, data: InvestmentInstitutionPayload) {
    return apiFetch<InvestmentInstitution>(`/investment-institutions/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteInvestmentInstitution(id: number) {
    return apiFetch<{ message: string }>(`/investment-institutions/${id}`, {
        method: 'DELETE',
    });
}

// ===== Financeiro =====

export function getFinanceSummary(month?: string) {
    const query = month ? `?month=${month}` : '';
    return apiFetch<FinanceSummary>(`/finance/summary${query}`);
}

export function getFinanceCategories(kind?: FinanceCategoryKind) {
    const query = kind ? `?kind=${kind}` : '';
    return apiFetch<FinanceCategory[]>(`/finance/categories${query}`);
}

export function createFinanceCategory(data: FinanceCategoryPayload) {
    return apiFetch<FinanceCategory>('/finance/categories', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateFinanceCategory(id: number, data: FinanceCategoryPayload) {
    return apiFetch<FinanceCategory>(`/finance/categories/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteFinanceCategory(id: number) {
    return apiFetch<{ message: string }>(`/finance/categories/${id}`, {
        method: 'DELETE',
    });
}

export function getAssets() {
    return apiFetch<AssetsResponse>('/assets');
}

export function createAsset(data: AssetPayload) {
    return apiFetch<Asset>('/assets', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateAsset(id: number, data: AssetPayload) {
    return apiFetch<Asset>(`/assets/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteAsset(id: number) {
    return apiFetch<{ message: string }>(`/assets/${id}`, {
        method: 'DELETE',
    });
}

export function getBankAccounts() {
    return apiFetch<BankAccountsResponse>('/finance/accounts');
}

export function createBankAccount(data: BankAccountPayload) {
    return apiFetch<BankAccount>('/finance/accounts', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateBankAccount(id: number, data: BankAccountPayload) {
    return apiFetch<BankAccount>(`/finance/accounts/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteBankAccount(id: number) {
    return apiFetch<{ message: string }>(`/finance/accounts/${id}`, {
        method: 'DELETE',
    });
}

export function getPayables(month?: string) {
    const query = month ? `?month=${month}` : '';
    return apiFetch<Payable[]>(`/finance/payables${query}`);
}

export function createPayable(data: PayablePayload) {
    return apiFetch<{ message: string; created: number }>('/finance/payables', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updatePayable(id: number, data: Omit<PayablePayload, 'kind' | 'installments_total'>) {
    return apiFetch<Payable>(`/finance/payables/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function payPayable(id: number, data: { bank_account_id: number; paid_at?: string }) {
    return apiFetch<Payable>(`/finance/payables/${id}/pay`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function unpayPayable(id: number) {
    return apiFetch<Payable>(`/finance/payables/${id}/unpay`, { method: 'POST' });
}

export function deletePayable(id: number, scope: 'one' | 'group' = 'one') {
    return apiFetch<{ message: string }>(`/finance/payables/${id}?scope=${scope}`, {
        method: 'DELETE',
    });
}

export function getReceivables(month?: string) {
    const query = month ? `?month=${month}` : '';
    return apiFetch<Receivable[]>(`/finance/receivables${query}`);
}

export function createReceivable(data: ReceivablePayload) {
    return apiFetch<{ message: string; created: number }>('/finance/receivables', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateReceivable(id: number, data: Omit<ReceivablePayload, 'kind'>) {
    return apiFetch<Receivable>(`/finance/receivables/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function receiveReceivable(id: number, data: { bank_account_id: number; received_at?: string }) {
    return apiFetch<Receivable>(`/finance/receivables/${id}/receive`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function unreceiveReceivable(id: number) {
    return apiFetch<Receivable>(`/finance/receivables/${id}/unreceive`, { method: 'POST' });
}

export function deleteReceivable(id: number, scope: 'one' | 'group' = 'one') {
    return apiFetch<{ message: string }>(`/finance/receivables/${id}?scope=${scope}`, {
        method: 'DELETE',
    });
}

// ===== Cartões de crédito =====

export function getCreditCards() {
    return apiFetch<CreditCardsResponse>('/finance/credit-cards');
}

export function createCreditCard(data: CreditCardPayload) {
    return apiFetch<CreditCard>('/finance/credit-cards', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateCreditCard(id: number, data: CreditCardPayload) {
    return apiFetch<CreditCard>(`/finance/credit-cards/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteCreditCard(id: number) {
    return apiFetch<{ message: string }>(`/finance/credit-cards/${id}`, {
        method: 'DELETE',
    });
}

export function resolveCreditCardInvoice(cardId: number, date: string) {
    return apiFetch<ResolvedInvoiceWindow>(`/finance/credit-cards/${cardId}/resolve-invoice?date=${date}`);
}

export function getCreditCardInvoice(cardId: number, month: string) {
    return apiFetch<CreditCardInvoice>(`/finance/credit-cards/${cardId}/invoices?month=${month}`);
}

export function createCreditCardTransaction(data: CreditCardTransactionPayload) {
    return apiFetch<{ message: string; created: number }>('/finance/credit-card-transactions', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateCreditCardTransaction(id: number, data: CreditCardTransactionUpdatePayload) {
    return apiFetch<CreditCardTransaction>(`/finance/credit-card-transactions/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteCreditCardTransaction(id: number, scope: 'one' | 'group' = 'one') {
    return apiFetch<{ message: string }>(`/finance/credit-card-transactions/${id}?scope=${scope}`, {
        method: 'DELETE',
    });
}

export function payCreditCardInvoice(invoiceId: number, data: CreditCardInvoicePaymentPayload) {
    return apiFetch<CreditCardInvoice>(`/finance/credit-cards/invoices/${invoiceId}/payments`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function deleteCreditCardInvoicePayment(invoiceId: number, paymentId: number) {
    return apiFetch<CreditCardInvoice>(`/finance/credit-cards/invoices/${invoiceId}/payments/${paymentId}`, {
        method: 'DELETE',
    });
}

// ===== Lançamento via IA (foto de comprovante) =====

export function parseAiReceipt(file: File) {
    const formData = new FormData();
    formData.append('file', file);

    return apiFetch<ReceiptParseResponse>('/finance/ai-receipt/parse', {
        method: 'POST',
        body: formData,
    });
}

export function confirmAiReceipt(data: AiReceiptConfirmPayload) {
    return apiFetch<{ message: string; created: number }>('/finance/ai-receipt/confirm', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function confirmAiReceiptBatch(data: AiReceiptBatchPayload) {
    return apiFetch<AiReceiptBatchResult>('/finance/ai-receipt/confirm-batch', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}
