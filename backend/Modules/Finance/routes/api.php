<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\AccountCategoryController;
use Modules\Finance\Http\Controllers\AttachmentController;
use Modules\Finance\Http\Controllers\BillController;
use Modules\Finance\Http\Controllers\BillPaymentController;
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
    Route::put('finance-settings/lock-date', [FinanceSettingsController::class, 'updateLockDate'])
        ->middleware('throttle:finance-writes')
        ->name('finance-settings.lock-date');
    Route::put('finance-settings/maker-checker', [FinanceSettingsController::class, 'updateMakerChecker'])
        ->middleware('throttle:finance-writes')
        ->name('finance-settings.maker-checker');

    // ── Payables: supplier bills ─────────────────────────────────────────────
    // Reads are unthrottled beyond the app default; every write goes through
    // the finance-writes limiter (FinanceServiceProvider).
    Route::get('bills/options', [BillController::class, 'options'])->name('bills.options');
    Route::get('bills/next-no', [BillController::class, 'nextNo'])->name('bills.next-no');
    Route::get('bills/supplier-defaults/{supplier}', [BillController::class, 'supplierDefaults'])
        ->whereNumber('supplier')
        ->name('bills.supplier-defaults');
    Route::get('bills/{bill}/history', [BillController::class, 'history'])
        ->whereNumber('bill')
        ->name('bills.history');

    Route::middleware('throttle:finance-writes')->group(function (): void {
        Route::post('bills/{bill}/confirm', [BillController::class, 'confirm'])
            ->whereNumber('bill')
            ->name('bills.confirm');
        Route::post('bills/{bill}/void', [BillController::class, 'void'])
            ->whereNumber('bill')
            ->name('bills.void');
        Route::post('bills', [BillController::class, 'store'])->name('bills.store');
        Route::match(['put', 'patch'], 'bills/{bill}', [BillController::class, 'update'])
            ->whereNumber('bill')
            ->name('bills.update');
        Route::delete('bills/{bill}', [BillController::class, 'destroy'])
            ->whereNumber('bill')
            ->name('bills.destroy');

        Route::post('bill-payments/{bill_payment}/confirm', [BillPaymentController::class, 'confirm'])
            ->whereNumber('bill_payment')
            ->name('bill-payments.confirm');
        Route::post('bill-payments/{bill_payment}/void', [BillPaymentController::class, 'void'])
            ->whereNumber('bill_payment')
            ->name('bill-payments.void');
        Route::post('bill-payments', [BillPaymentController::class, 'store'])->name('bill-payments.store');
        Route::match(['put', 'patch'], 'bill-payments/{bill_payment}', [BillPaymentController::class, 'update'])
            ->whereNumber('bill_payment')
            ->name('bill-payments.update');
        Route::delete('bill-payments/{bill_payment}', [BillPaymentController::class, 'destroy'])
            ->whereNumber('bill_payment')
            ->name('bill-payments.destroy');

        // Shared attachments for every Finance document type.
        Route::post('finance-attachments/{type}/{id}', [AttachmentController::class, 'store'])
            ->where('type', '[a-z_]+')->whereNumber('id')
            ->name('finance-attachments.store');
        Route::delete('finance-attachments/{attachment}', [AttachmentController::class, 'destroy'])
            ->whereNumber('attachment')
            ->name('finance-attachments.destroy');
    });

    // ── Payables: bill payments ──────────────────────────────────────────────
    Route::get('bill-payments/options', [BillPaymentController::class, 'options'])->name('bill-payments.options');
    Route::get('bill-payments/next-no', [BillPaymentController::class, 'nextNo'])->name('bill-payments.next-no');
    Route::get('bill-payments/open-bills', [BillPaymentController::class, 'openBills'])->name('bill-payments.open-bills');
    Route::get('bill-payments/{bill_payment}/history', [BillPaymentController::class, 'history'])
        ->whereNumber('bill_payment')
        ->name('bill-payments.history');
    Route::get('bill-payments', [BillPaymentController::class, 'index'])->name('bill-payments.index');
    Route::get('bill-payments/{bill_payment}', [BillPaymentController::class, 'show'])
        ->whereNumber('bill_payment')
        ->name('bill-payments.show');

    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('bills/{bill}', [BillController::class, 'show'])->whereNumber('bill')->name('bills.show');

    Route::get('finance-attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->whereNumber('attachment')
        ->name('finance-attachments.download');
    Route::get('finance-attachments/{type}/{id}', [AttachmentController::class, 'index'])
        ->where('type', '[a-z_]+')->whereNumber('id')
        ->name('finance-attachments.index');

    // ── Financial statements ─────────────────────────────────────────────────
    Route::get('finance-reports/trial-balance', [FinanceReportController::class, 'trialBalanceReport'])
        ->name('finance-reports.trial-balance');
});
