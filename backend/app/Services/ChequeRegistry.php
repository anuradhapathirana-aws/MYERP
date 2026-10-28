<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One cheque leaf, one payment -- across modules.
 *
 * A cheque book belongs to a bank account, not to a module. Inventory's
 * Supplier Payment and Finance's Bill Payment both draw cheques on the same
 * Bank ledger accounts, so a leaf used by one must be refused by the other.
 *
 * Lives in core and reads each module's table through a soft link, guarded
 * by hasTable, so neither module depends on the other being installed. Each
 * module still checks its OWN documents itself (with its own idempotency
 * rules); this answers only "is it used by the other one?".
 */
class ChequeRegistry
{
    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';
    public const SOURCE_BILL_PAYMENT     = 'bill_payment';

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * The document number of a live (not voided, not deleted) payment in
     * another module that already used this cheque on this account, or null.
     */
    public function usedElsewhere(int $ledgerAccountId, string $chequeNo, string $exceptSource): ?string
    {
        $chequeNo = trim($chequeNo);

        if ($ledgerAccountId <= 0 || $chequeNo === '') {
            return null;
        }

        if ($exceptSource !== self::SOURCE_SUPPLIER_PAYMENT && $this->hasTable('inv_supplier_payment_settlements')) {
            // Inventory keeps the cheque number in reference_no and tells a
            // cheque line by its mode code.
            $used = DB::table('inv_supplier_payment_settlements as s')
                ->join('inv_supplier_payments as p', 'p.id', '=', 's.payment_id')
                ->where('s.ledger_account_id', $ledgerAccountId)
                ->where('s.payment_mode_code', 'cheque')
                ->where('s.reference_no', $chequeNo)
                ->where('p.status', '!=', 'voided')
                ->whereNull('p.deleted_at')
                ->value('p.payment_no');

            if ($used !== null) {
                return (string) $used;
            }
        }

        if ($exceptSource !== self::SOURCE_BILL_PAYMENT && $this->hasTable('fin_bill_payments')) {
            $used = DB::table('fin_bill_payments')
                ->where('pay_from_account_id', $ledgerAccountId)
                ->where('cheque_no', mb_strtoupper($chequeNo))
                ->where('status', '!=', 'voided')
                ->whereNull('deleted_at')
                ->value('payment_no');

            if ($used !== null) {
                return (string) $used;
            }
        }

        return null;
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
