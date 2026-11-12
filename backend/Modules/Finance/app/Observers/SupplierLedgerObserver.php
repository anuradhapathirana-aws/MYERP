<?php

declare(strict_types=1);

namespace Modules\Finance\Observers;

use App\Models\SupplierMaster;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Services\PartyLedgerProvisioner;

/**
 * Gives a saved supplier its ledger account, so it can be picked on a Journal
 * Voucher before any document has posted to it.
 *
 * ── Golden Rule ──────────────────────────────────────────────────────────────
 * SupplierMaster is core, so Finance may watch it, and the dependency runs one
 * way: core and Inventory know nothing about this class. Registered by
 * FinanceServiceProvider, so it does not exist when the module is not installed.
 *
 * ── Never blocks the save ────────────────────────────────────────────────────
 * The supplier master belongs to purchasing, and a Finance problem (Accounts
 * Payable not mapped, control account out of codes) must not stop someone adding
 * a supplier. The account is created at first posting instead, which is where
 * the problem will surface in front of someone who can fix it.
 */
final class SupplierLedgerObserver
{
    public function __construct(
        private readonly SettingsService        $settings,
        private readonly PartyLedgerProvisioner $parties,
    ) {}

    public function saved(SupplierMaster $supplier): void
    {
        // Installed is not the same as bought.
        if (! $this->settings->isEnabled('module.finance')) {
            return;
        }

        try {
            $this->parties->provision(
                PartyLedgerProvisioner::PARTY_SUPPLIER,
                $supplier->id,
                $supplier->supplier_name,
            );
        } catch (ValidationException $e) {
            Log::warning('Supplier saved without a ledger account.', [
                'supplier_id' => $supplier->id,
                'reason'      => $e->getMessage(),
            ]);
        }
    }
}
