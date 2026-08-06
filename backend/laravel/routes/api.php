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
use App\Http\Controllers\Api\Saude\CardioSessaoController;
use App\Http\Controllers\Api\Saude\ExercicioController;
use App\Http\Controllers\Api\Saude\GarminController;
use App\Http\Controllers\Api\Saude\MetaController;
use App\Http\Controllers\Api\Saude\NutricaoController;
use App\Http\Controllers\Api\Saude\PesoController;
use App\Http\Controllers\Api\Saude\RefeicaoController;
use App\Http\Controllers\Api\Saude\SaudeOverviewController;
use App\Http\Controllers\Api\Saude\SuplementoCheckinController;
use App\Http\Controllers\Api\Saude\SuplementoController;
use App\Http\Controllers\Api\Saude\TreinoController;
use App\Http\Controllers\Api\Saude\TreinoSessaoController;
use App\Http\Controllers\Api\Tasks\BoardController;
use App\Http\Controllers\Api\Tasks\GamificationController;
use App\Http\Controllers\Api\Tasks\ProjectController;
use App\Http\Controllers\Api\Tasks\TaskColumnController;
use App\Http\Controllers\Api\Tasks\TaskController;
use App\Http\Controllers\Api\Tasks\TimeEntryController;
use App\Http\Controllers\Api\Tasks\TimeReportController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\Whatsapp\AnaliseController;
use App\Http\Controllers\Api\Whatsapp\ChatController as WhatsappChatController;
use App\Http\Controllers\Api\Whatsapp\InstanciaController as WhatsappInstanciaController;
use App\Http\Controllers\Api\Whatsapp\RelatorioController as WhatsappRelatorioController;
use App\Http\Controllers\Api\Whatsapp\WebhookController as WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/health', [HealthController::class, 'index']);

// Webhook da Evolution API (módulo WhatsApp). Público de propósito — a
// Evolution não sabe autenticar em JWT; a proteção é o token na query string,
// conferido no controller.
Route::post('/whatsapp/webhook/evolution', [WhatsappWebhookController::class, 'evolution']);

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

    // WhatsApp: monitoramento de conversas + relatórios/sugestões por IA
    Route::prefix('whatsapp')->group(function () {
        Route::get('/instancia', [WhatsappInstanciaController::class, 'show']);
        Route::post('/instancia', [WhatsappInstanciaController::class, 'store']);
        Route::put('/instancia', [WhatsappInstanciaController::class, 'update']);
        Route::delete('/instancia', [WhatsappInstanciaController::class, 'destroy']);
        Route::get('/instancia/qrcode', [WhatsappInstanciaController::class, 'qrcode']);
        Route::get('/instancia/status', [WhatsappInstanciaController::class, 'status']);
        Route::post('/instancia/webhook', [WhatsappInstanciaController::class, 'reconfigurarWebhook']);

        Route::get('/chats', [WhatsappChatController::class, 'index']);
        Route::get('/atencao', [WhatsappChatController::class, 'atencao']);
        Route::get('/chats/{chat}/mensagens', [WhatsappChatController::class, 'mensagens']);
        Route::post('/chats/{chat}/monitorar', [WhatsappChatController::class, 'monitorar']);
        Route::post('/chats/{chat}/arquivar', [WhatsappChatController::class, 'arquivar']);
        Route::post('/chats/{chat}/analisar', [AnaliseController::class, 'analisarChat']);

        Route::get('/sugestoes', [AnaliseController::class, 'sugestoes']);
        Route::post('/sugestoes/{sugestao}/aceitar', [AnaliseController::class, 'aceitar']);
        Route::post('/sugestoes/{sugestao}/descartar', [AnaliseController::class, 'descartar']);

        Route::get('/relatorios', [WhatsappRelatorioController::class, 'index']);
        Route::post('/relatorios/gerar', [WhatsappRelatorioController::class, 'gerar']);
        Route::get('/relatorios/{relatorio}', [WhatsappRelatorioController::class, 'show']);
    });

    // Saúde: check-in de suplementos + treinos A/B + peso e meta (escopado pelo usuário)
    Route::prefix('saude')->group(function () {
        Route::get('/overview', [SaudeOverviewController::class, 'overview']);

        Route::post('/suplementos/{suplemento}/checkin', [SuplementoCheckinController::class, 'store']);
        Route::delete('/suplementos/{suplemento}/checkin', [SuplementoCheckinController::class, 'destroy']);
        Route::apiResource('suplementos', SuplementoController::class)
            ->parameters(['suplementos' => 'suplemento'])
            ->except(['show', 'create', 'edit']);

        Route::post('/treinos/{treino}/exercicios/reorder', [ExercicioController::class, 'reorder']);
        Route::apiResource('treinos', TreinoController::class)
            ->parameters(['treinos' => 'treino'])
            ->except(['show', 'create', 'edit']);
        Route::apiResource('exercicios', ExercicioController::class)
            ->parameters(['exercicios' => 'exercicio'])
            ->only(['store', 'update', 'destroy']);

        Route::get('/sessoes', [TreinoSessaoController::class, 'index']);
        Route::post('/sessoes', [TreinoSessaoController::class, 'store']);
        Route::delete('/sessoes/{sessao}', [TreinoSessaoController::class, 'destroy']);

        // Cardio: várias sessões por dia (ao fim do A/B ou avulso). `/resumo`
        // precisa vir antes de `/{cardio}` para não ser capturado pelo binding.
        Route::get('/cardio/resumo', [CardioSessaoController::class, 'resumo']);
        Route::get('/cardio', [CardioSessaoController::class, 'index']);
        Route::post('/cardio', [CardioSessaoController::class, 'store']);
        Route::put('/cardio/{cardio}', [CardioSessaoController::class, 'update']);
        Route::delete('/cardio/{cardio}', [CardioSessaoController::class, 'destroy']);

        // Garmin Connect (via sidecar Python; ver config/garmin.php).
        Route::get('/garmin/status', [GarminController::class, 'status']);
        Route::post('/garmin/sincronizar', [GarminController::class, 'sincronizar']);

        Route::apiResource('pesos', PesoController::class)
            ->parameters(['pesos' => 'peso'])
            ->except(['show', 'create', 'edit']);

        Route::get('/meta', [MetaController::class, 'show']);
        Route::put('/meta', [MetaController::class, 'update']);

        // Diário alimentar: refeições (foto via WhatsApp ou manual) + metas do dia
        Route::get('/nutricao', [NutricaoController::class, 'overview']);
        Route::get('/nutricao/projecao', [NutricaoController::class, 'projecao']);
        Route::get('/refeicoes/{refeicao}/foto', [RefeicaoController::class, 'foto']);
        Route::apiResource('refeicoes', RefeicaoController::class)
            ->parameters(['refeicoes' => 'refeicao'])
            ->except(['show', 'create', 'edit']);
    });
});
