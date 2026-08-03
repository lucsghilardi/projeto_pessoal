import { ApiError, UnauthorizedError } from './apiError';
import { CreateUserPayload, UpdateUserPayload, User } from '@/types/User';
import { Investment, InvestmentPayload, InvestmentSummary } from '@/types/Investment';
import { InvestmentTag, InvestmentTagPayload } from '@/types/InvestmentTag';
import { InvestmentInstitution, InvestmentInstitutionPayload } from '@/types/InvestmentInstitution';
import { Asset, AssetPayload, AssetsResponse } from '@/types/Asset';
import {
    BulkDeleteTasksResult,
    CreateColumnPayload,
    CreateProjectPayload,
    CreateTaskPayload,
    MoveTaskPayload,
    Project,
    ProjectBoard,
    Task,
    TaskColumn,
    TasksOverview,
    UpdateColumnPayload,
    UpdateProjectPayload,
    UpdateTaskPayload,
} from '@/types/Task';
import { GamificationSummary, MoveTaskResult } from '@/types/Gamification';
import {
    ActiveTimer,
    CreateTimeEntryPayload,
    TimeEntry,
    TimeReport,
    UpdateTimeEntryPayload,
} from '@/types/Time';
import {
    AiReceiptBatchPayload,
    AiReceiptBatchResult,
    AiReceiptCheckDuplicatesPayload,
    AiReceiptCheckDuplicatesResult,
    AiReceiptConfirmPayload,
    ReceiptParseResponse,
} from '@/types/AiReceipt';
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
    Acerto,
    AcertoDirection,
    AcertoPayload,
    BankAccount,
    BankAccountPayload,
    BankAccountsResponse,
    FinanceCategory,
    FinanceCategoryKind,
    FinanceCategoryPayload,
    FinanceReport,
    FinanceSummary,
    Payable,
    PayablePayload,
    Receivable,
    ReceivablePayload,
} from '@/types/Finance';
import {
    Consorcio,
    ConsorcioParcelaGeneratePayload,
    ConsorcioParcelaPayload,
    ConsorcioParcelasResponse,
    ConsorcioPayload,
    ConsorcioReajusteResponse,
    ConsorciosResponse,
} from '@/types/Consorcio';
import {
    WhatsappAceitarSugestaoPayload,
    WhatsappAnaliseResultado,
    WhatsappAtencao,
    WhatsappChat,
    WhatsappInstancia,
    WhatsappInstanciaPayload,
    WhatsappMensagem,
    WhatsappQrcode,
    WhatsappRelatorio,
    WhatsappRelatorioTipo,
    WhatsappStatusResponse,
    WhatsappSugestao,
    WhatsappSugestaoStatus,
} from '@/types/Whatsapp';

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

    // Sem Accept explícito o Laravel responde erros de validação com redirect
    // HTML em vez de JSON 422 (o proxy repassa o Accept ao backend).
    if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json');
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

export function getFinanceReports(params?: { start?: string; end?: string }) {
    const query = new URLSearchParams();
    if (params?.start) query.set('start', params.start);
    if (params?.end) query.set('end', params.end);
    const qs = query.toString();
    return apiFetch<FinanceReport>(`/finance/reports${qs ? `?${qs}` : ''}`);
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

// ===== Acertos (a pagar/receber sem prazo) =====

export function getAcertos(direction?: AcertoDirection) {
    const query = direction ? `?direction=${direction}` : '';
    return apiFetch<Acerto[]>(`/finance/acertos${query}`);
}

export function createAcerto(data: AcertoPayload) {
    return apiFetch<Acerto>('/finance/acertos', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateAcerto(id: number, data: Omit<AcertoPayload, 'direction'>) {
    return apiFetch<Acerto>(`/finance/acertos/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteAcerto(id: number) {
    return apiFetch<{ message: string }>(`/finance/acertos/${id}`, {
        method: 'DELETE',
    });
}

export function settleAcerto(
    id: number,
    data: { amount: number; bank_account_id: number; settled_at?: string },
) {
    return apiFetch<Acerto>(`/finance/acertos/${id}/settlements`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function deleteAcertoSettlement(acertoId: number, settlementId: number) {
    return apiFetch<Acerto>(`/finance/acertos/${acertoId}/settlements/${settlementId}`, {
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

// ===== Consórcios =====

export function getConsorcios() {
    return apiFetch<ConsorciosResponse>('/consorcios');
}

export function createConsorcio(data: ConsorcioPayload) {
    return apiFetch<Consorcio>('/consorcios', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateConsorcio(id: number, data: ConsorcioPayload) {
    return apiFetch<Consorcio>(`/consorcios/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteConsorcio(id: number) {
    return apiFetch<{ message: string }>(`/consorcios/${id}`, {
        method: 'DELETE',
    });
}

export function uploadConsorcioProposta(id: number, file: File) {
    const formData = new FormData();
    formData.append('file', file);

    return apiFetch<Consorcio>(`/consorcios/${id}/proposta`, {
        method: 'POST',
        body: formData,
    });
}

export function deleteConsorcioProposta(id: number) {
    return apiFetch<Consorcio>(`/consorcios/${id}/proposta`, {
        method: 'DELETE',
    });
}

/** URL para abrir/baixar a proposta (passa pelo proxy autenticado). */
export function consorcioPropostaUrl(id: number) {
    return `${API_URL}/consorcios/${id}/proposta`;
}

export function getConsorcioParcelas(consorcioId: number) {
    return apiFetch<ConsorcioParcelasResponse>(`/consorcios/${consorcioId}/parcelas`);
}

export function createConsorcioParcela(consorcioId: number, data: ConsorcioParcelaPayload) {
    return apiFetch<ConsorcioParcelasResponse>(`/consorcios/${consorcioId}/parcelas`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function generateConsorcioParcelas(
    consorcioId: number,
    data: ConsorcioParcelaGeneratePayload,
) {
    return apiFetch<ConsorcioParcelasResponse & { criadas: number }>(
        `/consorcios/${consorcioId}/parcelas/generate`,
        {
            method: 'POST',
            body: JSON.stringify(data),
        },
    );
}

export function aplicarReajusteConsorcio(consorcioId: number, percentual: number) {
    return apiFetch<ConsorcioReajusteResponse>(`/consorcios/${consorcioId}/reajuste`, {
        method: 'POST',
        body: JSON.stringify({ percentual }),
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

// ===== Tarefas (projetos + kanban + gamificação) =====

export function getTasksOverview() {
    return apiFetch<TasksOverview>('/tasks-overview');
}

export function getGamification() {
    return apiFetch<GamificationSummary>('/gamification');
}

export function getProjects() {
    return apiFetch<Project[]>('/projects');
}

export function getProjectBoard(id: number) {
    return apiFetch<ProjectBoard>(`/projects/${id}`);
}

export function createProject(data: CreateProjectPayload) {
    return apiFetch<ProjectBoard>('/projects', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateProject(id: number, data: UpdateProjectPayload) {
    return apiFetch<Project>(`/projects/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteProject(id: number) {
    return apiFetch<{ message: string }>(`/projects/${id}`, {
        method: 'DELETE',
    });
}

export function createColumn(projectId: number, data: CreateColumnPayload) {
    return apiFetch<TaskColumn>(`/projects/${projectId}/columns`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateColumn(columnId: number, data: UpdateColumnPayload) {
    return apiFetch<TaskColumn>(`/columns/${columnId}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteColumn(columnId: number) {
    return apiFetch<{ message: string }>(`/columns/${columnId}`, {
        method: 'DELETE',
    });
}

export function reorderColumns(columnIds: number[]) {
    return apiFetch<{ message: string }>('/columns/reorder', {
        method: 'POST',
        body: JSON.stringify({ columns: columnIds }),
    });
}

export function getTasks() {
    return apiFetch<Task[]>('/tasks');
}

export function getTaskColumns() {
    return apiFetch<TaskColumn[]>('/columns');
}

export function bulkDeleteTasks(ids: number[]) {
    return apiFetch<BulkDeleteTasksResult>('/tasks/bulk-delete', {
        method: 'POST',
        body: JSON.stringify({ ids }),
    });
}

export function createTask(data: CreateTaskPayload) {
    return apiFetch<Task>('/tasks', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateTask(id: number, data: UpdateTaskPayload) {
    return apiFetch<Task>(`/tasks/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteTask(id: number) {
    return apiFetch<{ message: string }>(`/tasks/${id}`, {
        method: 'DELETE',
    });
}

export function moveTask(id: number, data: MoveTaskPayload) {
    return apiFetch<MoveTaskResult>(`/tasks/${id}/move`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

// ===== Tempo / Relatórios =====

export function getActiveTimer() {
    return apiFetch<ActiveTimer>('/time-entries/active');
}

export function startTimer(taskId: number) {
    return apiFetch<TimeEntry>('/time-entries/start', {
        method: 'POST',
        body: JSON.stringify({ task_id: taskId }),
    });
}

export function stopTimer(entryId: number) {
    return apiFetch<TimeEntry>(`/time-entries/${entryId}/stop`, { method: 'POST' });
}

export function getTaskTimeEntries(taskId: number) {
    return apiFetch<TimeEntry[]>(`/time-entries?task_id=${taskId}`);
}

export function createTimeEntry(data: CreateTimeEntryPayload) {
    return apiFetch<TimeEntry>('/time-entries', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateTimeEntry(id: number, data: UpdateTimeEntryPayload) {
    return apiFetch<TimeEntry>(`/time-entries/${id}`, {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteTimeEntry(id: number) {
    return apiFetch<{ message: string }>(`/time-entries/${id}`, { method: 'DELETE' });
}

export function getTimeReport(month?: string) {
    const query = month ? `?month=${month}` : '';
    return apiFetch<TimeReport>(`/time-reports${query}`);
}

export function confirmAiReceiptBatch(data: AiReceiptBatchPayload) {
    return apiFetch<AiReceiptBatchResult>('/finance/ai-receipt/confirm-batch', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function checkAiReceiptDuplicates(data: AiReceiptCheckDuplicatesPayload) {
    return apiFetch<AiReceiptCheckDuplicatesResult>('/finance/ai-receipt/check-duplicates', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

// ===== WhatsApp =====

export function getWhatsappInstancia() {
    return apiFetch<{ instancia: WhatsappInstancia | null }>('/whatsapp/instancia');
}

export function createWhatsappInstancia(data: { apelido?: string } = {}) {
    return apiFetch<{ instancia: WhatsappInstancia }>('/whatsapp/instancia', {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function updateWhatsappInstancia(data: WhatsappInstanciaPayload) {
    return apiFetch<{ instancia: WhatsappInstancia }>('/whatsapp/instancia', {
        method: 'PUT',
        body: JSON.stringify(data),
    });
}

export function deleteWhatsappInstancia() {
    return apiFetch<{ message: string }>('/whatsapp/instancia', { method: 'DELETE' });
}

export function getWhatsappQrcode() {
    return apiFetch<WhatsappQrcode>('/whatsapp/instancia/qrcode');
}

export function getWhatsappStatus() {
    return apiFetch<WhatsappStatusResponse>('/whatsapp/instancia/status');
}

export function reconfigurarWhatsappWebhook() {
    return apiFetch<{ message: string }>('/whatsapp/instancia/webhook', { method: 'POST' });
}

export function getWhatsappChats(params: { busca?: string; monitorados?: boolean; arquivados?: boolean } = {}) {
    const query = new URLSearchParams();
    if (params.busca) query.set('busca', params.busca);
    if (params.monitorados) query.set('monitorados', '1');
    if (params.arquivados) query.set('arquivados', '1');
    const qs = query.toString();
    return apiFetch<{ chats: WhatsappChat[] }>(`/whatsapp/chats${qs ? `?${qs}` : ''}`);
}

export function getWhatsappMensagens(chatId: number, beforeMomment?: number) {
    const qs = beforeMomment ? `?before_momment=${beforeMomment}` : '';
    return apiFetch<{ chat: WhatsappChat; mensagens: WhatsappMensagem[] }>(
        `/whatsapp/chats/${chatId}/mensagens${qs}`,
    );
}

export function setWhatsappMonitorado(chatId: number, monitorado: boolean) {
    return apiFetch<{ chat: WhatsappChat }>(`/whatsapp/chats/${chatId}/monitorar`, {
        method: 'POST',
        body: JSON.stringify({ monitorado }),
    });
}

export function setWhatsappArquivado(chatId: number, arquivado: boolean) {
    return apiFetch<{ chat: WhatsappChat }>(`/whatsapp/chats/${chatId}/arquivar`, {
        method: 'POST',
        body: JSON.stringify({ arquivado }),
    });
}

export function analisarWhatsappChat(chatId: number, periodoDias: number) {
    return apiFetch<WhatsappAnaliseResultado>(`/whatsapp/chats/${chatId}/analisar`, {
        method: 'POST',
        body: JSON.stringify({ periodo_dias: periodoDias }),
    });
}

export function getWhatsappAtencao() {
    return apiFetch<WhatsappAtencao>('/whatsapp/atencao');
}

export function getWhatsappSugestoes(status: WhatsappSugestaoStatus = 'pendente') {
    return apiFetch<{ sugestoes: WhatsappSugestao[] }>(`/whatsapp/sugestoes?status=${status}`);
}

export function aceitarWhatsappSugestao(id: number, data: WhatsappAceitarSugestaoPayload = {}) {
    return apiFetch<{ sugestao: WhatsappSugestao; task: Task }>(`/whatsapp/sugestoes/${id}/aceitar`, {
        method: 'POST',
        body: JSON.stringify(data),
    });
}

export function descartarWhatsappSugestao(id: number) {
    return apiFetch<{ sugestao: WhatsappSugestao }>(`/whatsapp/sugestoes/${id}/descartar`, {
        method: 'POST',
    });
}

export function getWhatsappRelatorios() {
    return apiFetch<{ relatorios: WhatsappRelatorio[] }>('/whatsapp/relatorios');
}

export function getWhatsappRelatorio(id: number) {
    return apiFetch<{ relatorio: WhatsappRelatorio }>(`/whatsapp/relatorios/${id}`);
}

export function gerarWhatsappRelatorio(tipo: WhatsappRelatorioTipo, enviar = true) {
    return apiFetch<{ relatorio: WhatsappRelatorio }>('/whatsapp/relatorios/gerar', {
        method: 'POST',
        body: JSON.stringify({ tipo, enviar }),
    });
}
