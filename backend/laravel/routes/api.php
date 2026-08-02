<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsorcioController;
use App\Http\Controllers\Api\ConsorcioParcelaController;
use App\Http\Controllers\Api\Finance\AcertoController;
use App\Http\Controllers\Api\Finance\AiReceiptController;
use App\Http\Controllers\Api\Finance\BankAccountController;
use App\Http\Controllers\Api\Finance\CreditCardController;
use App\Http\Controllers\Api\Finance\CreditCardInvoiceController;
use App\Http\Controllers\Api\Finance\CreditCardTransactionController;
use App\Http\Controllers\Api\Finance\FinanceCategoryController;
use App\Http\Controllers\Api\Finance\FinanceReportController;
use App\Http\Controllers\Api\Finance\FinanceSummaryController;
use App\Http\Controllers\Api\Finance\PayableController;
use App\Http\Controllers\Api\Finance\ReceivableController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvestmentController;
use App\Http\Controllers\Api\InvestmentInstitutionController;
use App\Http\Controllers\Api\InvestmentTagController;
use App\Http\Controllers\Api\Tasks\BoardController;
use App\Http\Controllers\Api\Tasks\GamificationController;
use App\Http\Controllers\Api\Tasks\ProjectController;
use App\Http\Controllers\Api\Tasks\TaskColumnController;
use App\Http\Controllers\Api\Tasks\TaskController;
use App\Http\Controllers\Api\Tasks\TimeEntryController;
use App\Http\Controllers\Api\Tasks\TimeReportController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/health', [HealthController::class, 'index']);

Route::middleware(['auth:api', 'panel.active'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);

    // Investimentos (escopados pelo usuario autenticado)
    Route::get('/investments/summary', [InvestmentController::class, 'summary']);
    Route::get('/investments/exchange-rate', [InvestmentController::class, 'exchangeRate']);
    Route::put('/investments/values', [InvestmentController::class, 'bulkUpdateValues']);
    Route::post('/investments/{investment}/contributions', [InvestmentController::class, 'contribute']);
    Route::apiResource('investments', InvestmentController::class)->except(['show']);
    Route::apiResource('investment-tags', InvestmentTagController::class)->except(['show']);
    Route::apiResource('investment-institutions', InvestmentInstitutionController::class)->except(['show']);

    // Patrimônios (escopado pelo usuario autenticado)
    Route::apiResource('assets', AssetController::class)->except(['show']);

    // Consórcios: contrato + proposta (arquivo). As parcelas do carnê são contas a pagar
    // vinculadas (consorcio_id); pagar/estornar/editar/excluir usa os endpoints de payables.
    Route::post('/consorcios/{consorcio}/reajuste', [ConsorcioController::class, 'aplicarReajuste']);
    Route::post('/consorcios/{consorcio}/proposta', [ConsorcioController::class, 'uploadProposta']);
    Route::get('/consorcios/{consorcio}/proposta', [ConsorcioController::class, 'downloadProposta']);
    Route::delete('/consorcios/{consorcio}/proposta', [ConsorcioController::class, 'deleteProposta']);
    Route::get('/consorcios/{consorcio}/parcelas', [ConsorcioParcelaController::class, 'index']);
    Route::post('/consorcios/{consorcio}/parcelas', [ConsorcioParcelaController::class, 'store']);
    Route::post('/consorcios/{consorcio}/parcelas/generate', [ConsorcioParcelaController::class, 'generate']);
    Route::apiResource('consorcios', ConsorcioController::class)->except(['show']);

    // Tarefas: projetos + kanban (colunas) + gamificação (escopado pelo usuario)
    Route::get('/tasks-overview', [BoardController::class, 'overview']);
    Route::get('/gamification', [GamificationController::class, 'show']);
    Route::get('/columns', [TaskColumnController::class, 'index']);
    Route::post('/columns/reorder', [TaskColumnController::class, 'reorder']);
    Route::post('/tasks/bulk-delete', [TaskController::class, 'bulkDestroy']);
    Route::post('/tasks/{task}/move', [TaskController::class, 'move']);
    Route::apiResource('projects', ProjectController::class)->except(['create', 'edit']);
    Route::apiResource('projects.columns', TaskColumnController::class)
        ->shallow()
        ->parameters(['columns' => 'taskColumn'])
        ->only(['store', 'update', 'destroy']);
    Route::apiResource('tasks', TaskController::class)->except(['show', 'create', 'edit']);

    // Controle de tempo + relatórios (escopado pelo usuario)
    Route::get('/time-entries/active', [TimeEntryController::class, 'active']);
    Route::post('/time-entries/start', [TimeEntryController::class, 'start']);
    Route::post('/time-entries/{timeEntry}/stop', [TimeEntryController::class, 'stop']);
    Route::apiResource('time-entries', TimeEntryController::class)->except(['show', 'create', 'edit']);
    Route::get('/time-reports', [TimeReportController::class, 'index']);

    // Financeiro (escopado pelo usuario autenticado)
    Route::prefix('finance')->group(function () {
        Route::get('/summary', [FinanceSummaryController::class, 'index']);
        Route::get('/reports', [FinanceReportController::class, 'index']);

        // Lançamento de despesas via IA (foto de comprovante -> extração -> confirmação)
        Route::post('/ai-receipt/parse', [AiReceiptController::class, 'parse']);
        Route::post('/ai-receipt/check-duplicates', [AiReceiptController::class, 'checkDuplicates']);
        Route::post('/ai-receipt/confirm', [AiReceiptController::class, 'confirm']);
        Route::post('/ai-receipt/confirm-batch', [AiReceiptController::class, 'confirmBatch']);
        Route::get('/receipts/{type}/{id}', [AiReceiptController::class, 'download']);

        Route::apiResource('categories', FinanceCategoryController::class)
            ->parameters(['categories' => 'financeCategory'])->except(['show']);
        Route::apiResource('accounts', BankAccountController::class)
            ->parameters(['accounts' => 'bankAccount'])->except(['show']);

        Route::post('/payables/{payable}/pay', [PayableController::class, 'pay']);
        Route::post('/payables/{payable}/unpay', [PayableController::class, 'unpay']);
        Route::apiResource('payables', PayableController::class)->except(['show']);

        Route::post('/receivables/{receivable}/receive', [ReceivableController::class, 'receive']);
        Route::post('/receivables/{receivable}/unreceive', [ReceivableController::class, 'unreceive']);
        Route::apiResource('receivables', ReceivableController::class)->except(['show']);

        // Acertos: a pagar/receber sem prazo, com baixa parcial e histórico.
        Route::post('/acertos/{acerto}/settlements', [AcertoController::class, 'settle']);
        Route::delete('/acertos/{acerto}/settlements/{settlement}', [AcertoController::class, 'unsettle']);
        Route::apiResource('acertos', AcertoController::class)->except(['show']);

        // Cartões de crédito (cartão -> fatura -> lançamentos + pagamentos)
        Route::get('/credit-cards/{creditCard}/resolve-invoice', [CreditCardTransactionController::class, 'resolveInvoice']);
        Route::get('/credit-cards/{creditCard}/invoices', [CreditCardInvoiceController::class, 'index']);
        Route::post('/credit-cards/invoices/{invoice}/payments', [CreditCardInvoiceController::class, 'pay']);
        Route::delete('/credit-cards/invoices/{invoice}/payments/{payment}', [CreditCardInvoiceController::class, 'unpay']);
        Route::apiResource('credit-card-transactions', CreditCardTransactionController::class)
            ->parameters(['credit-card-transactions' => 'creditCardTransaction'])->except(['show', 'index']);
        Route::apiResource('credit-cards', CreditCardController::class)
            ->parameters(['credit-cards' => 'creditCard'])->except(['show']);
    });
});
