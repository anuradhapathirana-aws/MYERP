<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Support\Money\TableAmountPrecisionUsage;

/**
 * Finance's documents and money columns, for the Amount decimals lock and the
 * precision audit (see App\Contracts\AmountPrecisionUsage).
 *
 * Exchange rates and tax RATES are not amounts and are not listed.
 */
final class FinanceAmountPrecision extends TableAmountPrecisionUsage
{
    protected function documents(): array
    {
        return [
            'fin_journal_entries'     => ['journal', 'journals'],
            'fin_bills'               => ['bill', 'bills'],
            'fin_bill_payments'       => ['bill payment', 'bill payments'],
            'fin_bank_deposits'       => ['bank deposit', 'bank deposits'],
            'fin_bank_reconciliations' => ['bank reconciliation', 'bank reconciliations'],
        ];
    }

    public function moneyColumns(): array
    {
        return [
            'fin_journal_entries'           => ['total_debit', 'total_credit'],
            'fin_journal_lines'             => ['debit', 'credit'],
            'fin_bills'                     => ['total_amount', 'tax_amount', 'paid_amount'],
            'fin_bill_lines'                => ['amount', 'amount_incl', 'tax_amount', 'line_total', 'recoverable_tax'],
            'fin_bill_line_taxes'           => ['base', 'amount'],
            'fin_bill_payments'             => ['total_amount'],
            'fin_bill_payment_allocations'  => ['amount', 'balance_before'],
            'fin_bank_deposits'             => ['cash_total', 'cheque_total', 'total_amount'],
            'fin_bank_deposit_lines'        => ['amount'],
            'fin_bank_reconciliations'      => ['opening_balance', 'statement_balance', 'cleared_deposits', 'cleared_withdrawals', 'closing_balance', 'difference', 'book_balance'],
            'fin_bank_reconciliation_lines' => ['amount'],
        ];
    }
}
