<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Finance\BankAccountController;
use App\Http\Controllers\Api\Finance\FinanceCategoryController;
use App\Http\Controllers\Api\Finance\FinanceSummaryController;
use App\Http\Controllers\Api\Finance\PayableController;
use App\Http\Controllers\Api\Finance\ReceivableController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InvestmentController;
use App\Http\Controllers\Api\InvestmentInstitutionController;
use App\Http\Controllers\Api\InvestmentTagController;
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

    // Financeiro (escopado pelo usuario autenticado)
    Route::prefix('finance')->group(function () {
        Route::get('/summary', [FinanceSummaryController::class, 'index']);

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
    });
});
