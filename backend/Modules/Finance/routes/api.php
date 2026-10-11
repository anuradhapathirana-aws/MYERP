<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\AccountCategoryController;
use Modules\Finance\Http\Controllers\ControlAccountController;
use Modules\Finance\Http\Controllers\FinanceReportController;
use Modules\Finance\Http\Controllers\FinanceSettingsController;
use Modules\Finance\Http\Controllers\JournalEntryController;
use Modules\Finance\Http\Controllers\LedgerAccountController;

/*
|--------------------------------------------------------------------------
| Finance API
|--------------------------------------------------------------------------
|
| Gated by module:finance alone — nothing here is reachable on an
| Inventory-only installation, which is the whole point of selling the
| modules separately. The shared masters these screens depend on (banks,
| bank branches) live in core and carry their own any-of gate.
|
| Per-action permissions stay in each controller's constructor, matching the
| convention used throughout the codebase.
|
| Static routes are declared BEFORE apiResource so /all, /next-code and
| /account-types are not swallowed by the {model} wildcard.
|
*/

Route::middleware(['auth:sanctum', 'module:finance'])->prefix('v1')->group(function (): void {
    // ── Chart of accounts: level 1 ───────────────────────────────────────────
    Route::get('account-categories/account-types', [AccountCategoryController::class, 'accountTypes'])
        ->name('account-categories.account-types');
    Route::get('account-categories/all', [AccountCategoryController::class, 'all'])
        ->name('account-categories.all');
    Route::get('account-categories/next-code', [AccountCategoryController::class, 'nextCode'])
        ->name('account-categories.next-code');
    Route::apiResource('account-categories', AccountCategoryController::class)
        ->names('account-categories');

    // ── Chart of accounts: level 2 ───────────────────────────────────────────
    Route::get('control-accounts/all', [ControlAccountController::class, 'all'])
        ->name('control-accounts.all');
    Route::get('control-accounts/next-code', [ControlAccountController::class, 'nextCode'])
        ->name('control-accounts.next-code');
    Route::apiResource('control-accounts', ControlAccountController::class)
        ->names('control-accounts');

    // ── Chart of accounts: level 3 (the only postable level) ─────────────────
    Route::get('ledger-accounts/cash-book-types', [LedgerAccountController::class, 'cashBookTypes'])
        ->name('ledger-accounts.cash-book-types');
    Route::get('ledger-accounts/all', [LedgerAccountController::class, 'all'])
        ->name('ledger-accounts.all');
    Route::get('ledger-accounts/next-code', [LedgerAccountController::class, 'nextCode'])
        ->name('ledger-accounts.next-code');
    Route::apiResource('ledger-accounts', LedgerAccountController::class)
        ->names('ledger-accounts');

    // ── General ledger: journal vouchers ─────────────────────────────────────
    // No update route, deliberately. A posted journal is immutable and a draft
    // is replaced rather than edited, so no endpoint exists that could rewrite
    // an entry already in the books. Correction is reverse(), which posts a
    // mirror image and leaves the original standing.
    Route::get('journal-entries/options', [JournalEntryController::class, 'options'])
        ->name('journal-entries.options');
    Route::get('journal-entries/next-no', [JournalEntryController::class, 'nextNo'])
        ->name('journal-entries.next-no');
    Route::post('journal-entries/{journal_entry}/post', [JournalEntryController::class, 'post'])
        ->name('journal-entries.post');
    Route::post('journal-entries/{journal_entry}/reverse', [JournalEntryController::class, 'reverse'])
        ->name('journal-entries.reverse');
    Route::apiResource('journal-entries', JournalEntryController::class)
        ->only(['index', 'show', 'store', 'destroy'])
        ->names('journal-entries');

    // ── Account mapping and posting mode ─────────────────────────────────────
    Route::get('finance-settings', [FinanceSettingsController::class, 'index'])
        ->name('finance-settings.index');
    Route::put('finance-settings/mappings', [FinanceSettingsController::class, 'update'])
        ->name('finance-settings.mappings');
    Route::put('finance-settings/posting-mode', [FinanceSettingsController::class, 'updatePostingMode'])
        ->name('finance-settings.posting-mode');
    Route::put('finance-settings/party-ledger-mode', [FinanceSettingsController::class, 'updatePartyLedgerMode'])
        ->name('finance-settings.party-ledger-mode');

    // ── Financial statements ─────────────────────────────────────────────────
    Route::get('finance-reports/trial-balance', [FinanceReportController::class, 'trialBalanceReport'])
        ->name('finance-reports.trial-balance');
});
