<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\InvoiceStatus;

class SalesSummaryReportService
{
    /**
     * Classifies a settlement's admin-configured payment mode (inv_payment_modes has no
     * fixed code list — it's a free-form master table) into one of the four cash-in
     * columns this report shows. Matched on both `code` and `payment_mode_name` so it
     * still works if a client names their mode differently ("Card Payment" instead of
     * "Card", "Bank" instead of "Bank Deposit"). A mode matching none of these (e.g.
     * "Setoff", which isn't money changing hands) is left out of the report entirely.
     */
    private const BUCKET_CASE_SQL = <<<'SQL'
        CASE
            WHEN LOWER(s.payment_mode_code) = 'cash'
                OR LOWER(s.payment_mode_name) LIKE '%cash%' THEN 'cash'
            WHEN LOWER(s.payment_mode_code) IN ('cheque', 'check')
                OR LOWER(s.payment_mode_name) LIKE '%cheque%'
                OR LOWER(s.payment_mode_name) LIKE '%check%' THEN 'cheque'
            WHEN LOWER(s.payment_mode_code) IN ('card', 'cards', 'credit_card', 'debit_card')
                OR LOWER(s.payment_mode_name) LIKE '%card%' THEN 'cards'
            WHEN LOWER(s.payment_mode_code) IN ('bank_deposit', 'bank_transfer', 'banktransfer', 'deposit', 'bank')
                OR LOWER(s.payment_mode_name) LIKE '%bank%'
                OR LOWER(s.payment_mode_name) LIKE '%deposit%' THEN 'bank_deposit'
            ELSE NULL
        END
        SQL;

    /**
     * Build the Sales Summary dataset.
     *
     * The date-wise table is purely a record of money COLLECTED: one row per calendar
     * date, broken down by payment method (Cash / Cheque / Bank Deposit / Cards), all
     * sourced from confirmed Customer Receipt settlements and keyed by receipt_date.
     * "Total Collected" is the sum of those four columns — a cashier's daily takings
     * sheet, not a P&L. A date with invoices but no receipts produces no row.
     *
     * Uncollected money is deliberately NOT a table column: it belongs to the invoice's
     * date, not to any collection date, so mixing the two in one row is what caused
     * amounts to be counted twice. It is reported once, period-wide, in the header as
     * "Uncollected" — invoices issued inside the period minus what had been received
     * against them AS AT date_to. Anchoring to date_to (rather than "now") keeps a
     * closed period reproducible: re-running last month next year returns the same
     * figure instead of shrinking every time an old invoice is paid.
     *
     * Shared by the JSON, PDF and CSV endpoints so the aggregation logic exists once.
     *
     * @param array{date_from?:string|null, date_to?:string|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;

        // Plain DATE columns — compare as Y-m-d strings, no DATE() wrap, stays sargable.
        $fromDate = $dateFrom ? Carbon::parse($dateFrom)->toDateString() : null;
        $toDate   = $dateTo ? Carbon::parse($dateTo)->toDateString() : null;

        $collections = DB::table('inv_customer_receipt_settlements as s')
            ->join('inv_customer_receipts as r', 'r.id', '=', 's.receipt_id')
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->whereNull('r.deleted_at')
            ->when($fromDate, fn ($q) => $q->where('r.receipt_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('r.receipt_date', '<=', $toDate))
            ->groupBy('r.receipt_date')
            ->orderBy('r.receipt_date')
            ->selectRaw(
                'r.receipt_date as sale_date,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cash\' THEN s.amount ELSE 0 END) as cash,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cheque\' THEN s.amount ELSE 0 END) as cheque,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'bank_deposit\' THEN s.amount ELSE 0 END) as bank_deposit,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cards\' THEN s.amount ELSE 0 END) as cards'
            )
            ->get();

        // Money received per invoice as at date_to — a discount is a permanent write-off
        // so it settles the invoice exactly like received cash, mirroring
        // CustomerReceiptService::computeOutstanding().
        $receivedPerInvoice = DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('a.reference_type', 'invoice')
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->whereNull('r.deleted_at')
            ->when($toDate, fn ($q) => $q->where('r.receipt_date', '<=', $toDate))
            ->groupBy('a.reference_id')
            ->selectRaw('a.reference_id as invoice_id, SUM(a.receipt_amount + a.discount) as received');

        // Bill count, invoiced revenue and still-uncollected money for the period — all
        // three describe the same set of invoices, so one pass over it answers all of
        // them. Independent of how or when the money is collected, unlike the
        // payment-method columns above. GREATEST(..., 0) clamps per invoice so an
        // overpaid one (which becomes a credit note here, not a negative receivable)
        // can't mask another invoice's genuine shortfall.
        $billStats = DB::table('inv_invoices as i')
            ->leftJoinSub($receivedPerInvoice, 'rc', 'rc.invoice_id', '=', 'i.id')
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->whereNull('i.deleted_at')
            ->when($fromDate, fn ($q) => $q->where('i.invoice_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('i.invoice_date', '<=', $toDate))
            ->selectRaw(
                'COUNT(*) as bill_count,
                 COALESCE(SUM(i.grand_total), 0) as net_sale,
                 COALESCE(SUM(GREATEST(i.grand_total - COALESCE(rc.received, 0), 0)), 0) as uncollected'
            )
            ->first();

        $rows = [];
        $totals = ['cash' => 0.0, 'cheque' => 0.0, 'bank_deposit' => 0.0, 'cards' => 0.0, 'total_collected' => 0.0];

        foreach ($collections as $c) {
            $cash           = (float) $c->cash;
            $cheque         = (float) $c->cheque;
            $bankDeposit    = (float) $c->bank_deposit;
            $cards          = (float) $c->cards;
            $totalCollected = $cash + $cheque + $bankDeposit + $cards;

            $rows[] = [
                'date'            => $c->sale_date,
                'cash'            => $cash,
                'cheque'          => $cheque,
                'bank_deposit'    => $bankDeposit,
                'cards'           => $cards,
                'total_collected' => $totalCollected,
            ];

            $totals['cash']            += $cash;
            $totals['cheque']          += $cheque;
            $totals['bank_deposit']    += $bankDeposit;
            $totals['cards']           += $cards;
            $totals['total_collected'] += $totalCollected;
        }

        return [
            'header'  => $this->buildHeader(
                $dateFrom,
                $dateTo,
                $totals,
                (int) $billStats->bill_count,
                (float) $billStats->net_sale,
                (float) $billStats->uncollected,
            ),
            'rows'    => $rows,
            'summary' => $totals,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(
        ?string $dateFrom,
        ?string $dateTo,
        array $summary,
        int $billCount,
        float $netSale,
        float $uncollected,
    ): array {
        // Single-tenant deployment: the report always belongs to the one primary company.
        $company = DB::table('inv_companies')->orderBy('id')->first();

        return [
            'company_name'    => $company->company_name ?? null,
            'company_address' => $company
                ? collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ')
                : null,
            'company_email'   => $company->company_email ?? null,
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'total_collected' => $summary['total_collected'],
            // Bill count + invoiced revenue for the period (independent of collection).
            'bill_count'      => $billCount,
            'net_sale'        => $netSale,
            // Money actually collected for the period, split cash vs. everything else —
            // Non-Cash Collected rolls Cheque + Bank Deposit + Cards together.
            'cash_collected'     => $summary['cash'],
            'non_cash_collected' => $summary['cheque'] + $summary['bank_deposit'] + $summary['cards'],
            // Invoiced inside the period but still not received as at date_to.
            'uncollected'        => $uncollected,
            'generated_by'    => Auth::user()?->name,
            'generated_at'    => now()->toDateTimeString(),
        ];
    }
}
