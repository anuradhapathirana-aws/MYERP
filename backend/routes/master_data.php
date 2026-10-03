<?php

declare(strict_types=1);

use App\Http\Controllers\BankBranchController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\IndustryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PaymentModeController;
use App\Http\Controllers\SupplierAttachmentController;
use App\Http\Controllers\SupplierGroupController;
use App\Http\Controllers\SupplierMasterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shared core master data
|--------------------------------------------------------------------------
|
| Master data used by more than one module lives in core, which is always
| installed and never sold. The group in routes/api.php gates these with an
| any-of module check so a Finance-only or Inventory-only install both reach
| them.
|
| The URI segments are deliberately IDENTICAL to their previous in-module
| paths: FormRequests read route parameters by name (CompanyRequest reads
| $this->route('company')) and implicit model binding derives the binding
| from the same segment. Renaming a segment would silently break both, and
| every frontend api/*.js file would need editing.
|
| Per-action permissions stay in each controller's constructor, unchanged.
| Static routes are declared before apiResource so segments like /all are not
| swallowed by the {model} wildcard.
|
*/

// ── Organisation ─────────────────────────────────────────────────────────────
Route::get('industries/all', [IndustryController::class, 'all'])->name('industries.all');
Route::apiResource('industries', IndustryController::class)->names('industries');

Route::get('companies/all', [CompanyController::class, 'all'])->name('companies.all');
Route::apiResource('companies', CompanyController::class)->names('companies');

Route::get('locations/all', [LocationController::class, 'all'])->name('locations.all');
Route::apiResource('locations', LocationController::class)->names('locations');

// ── People ───────────────────────────────────────────────────────────────────
Route::get('employees/all', [EmployeeController::class, 'all'])->name('employees.all');
Route::apiResource('employees', EmployeeController::class)->names('employees');

// ── Suppliers ────────────────────────────────────────────────────────────────
Route::get('supplier-groups/all', [SupplierGroupController::class, 'all'])->name('supplier-groups.all');
Route::apiResource('supplier-groups', SupplierGroupController::class)->names('supplier-groups');

Route::get('supplier-masters/all', [SupplierMasterController::class, 'all'])->name('supplier-masters.all');
Route::get('supplier-masters/next-code', [SupplierMasterController::class, 'nextSupplierCode'])
    ->name('supplier-masters.next-code');
Route::apiResource('supplier-masters', SupplierMasterController::class)->names('supplier-masters');

Route::get('supplier-masters/{supplier_master}/attachments', [SupplierAttachmentController::class, 'index'])
    ->name('supplier-masters.attachments.index');
Route::post('supplier-masters/{supplier_master}/attachments', [SupplierAttachmentController::class, 'store'])
    ->name('supplier-masters.attachments.store');
Route::delete('supplier-masters/{supplier_master}/attachments/{attachment}', [SupplierAttachmentController::class, 'destroy'])
    ->name('supplier-masters.attachments.destroy');

// ── Banking & payments ───────────────────────────────────────────────────────
Route::get('banks/all', [BankController::class, 'all'])->name('banks.all');
Route::apiResource('banks', BankController::class)->names('banks');

Route::get('bank-branches/all', [BankBranchController::class, 'all'])->name('bank-branches.all');
Route::apiResource('bank-branches', BankBranchController::class)->names('bank-branches');

Route::get('payment-modes/all', [PaymentModeController::class, 'all'])->name('payment-modes.all');
Route::apiResource('payment-modes', PaymentModeController::class)->names('payment-modes');
