<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\PaymentMode;

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
     * Build the Sales Summary dataset: one row per calendar date with money actually
     * collected that day broken down by payment method (Cash / Cheque / Bank Deposit /
     * Cards — sourced from confirmed Customer Receipt settlements, keyed by
     * receipt_date), plus new Credit sales issued that day (Invoice.mode_of_payment =
     * Credit, keyed by invoice_date). "Total Sales" is the sum of those five columns —
     * it is deliberately NOT the day's total invoiced revenue, since Credit sales and
     * actual collections are two different things happening on two different dates;
     * this mirrors a cashier's daily takings sheet, not a P&L.
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
            ->selectRaw(
                'r.receipt_date as sale_date,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cash\' THEN s.amount ELSE 0 END) as cash,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cheque\' THEN s.amount ELSE 0 END) as cheque,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'bank_deposit\' THEN s.amount ELSE 0 END) as bank_deposit,
                 SUM(CASE WHEN (' . self::BUCKET_CASE_SQL . ') = \'cards\' THEN s.amount ELSE 0 END) as cards'
            )
            ->get()
            ->keyBy('sale_date');

        $creditSales = DB::table('inv_invoices as i')
            ->where('i.mode_of_payment', PaymentMode::Credit->value)
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->whereNull('i.deleted_at')
            ->when($fromDate, fn ($q) => $q->where('i.invoice_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('i.invoice_date', '<=', $toDate))
            ->groupBy('i.invoice_date')
            ->selectRaw('i.invoice_date as sale_date, SUM(i.grand_total) as credit')
            ->get()
            ->keyBy('sale_date');

        // Bill count + invoiced revenue for the period — independent of how/when it's
        // collected, unlike the payment-method columns above. Powers the "Number of
        // Bills" / "Net Sale" header stats.
        $billStats = DB::table('inv_invoices as i')
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->whereNull('i.deleted_at')
            ->when($fromDate, fn ($q) => $q->where('i.invoice_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('i.invoice_date', '<=', $toDate))
            ->selectRaw('COUNT(*) as bill_count, COALESCE(SUM(i.grand_total), 0) as net_sale')
            ->first();

        // A date can appear in either side alone (e.g. only credit sales, no cash
        // collected that day, or vice versa) — the report needs a row either way.
        $dates = $collections->keys()->merge($creditSales->keys())->unique()->sort()->values();

        $rows = [];
        $totals = ['cash' => 0.0, 'credit' => 0.0, 'cheque' => 0.0, 'bank_deposit' => 0.0, 'cards' => 0.0, 'total_sales' => 0.0];

        foreach ($dates as $date) {
            $c = $collections->get($date);
            $cash        = (float) ($c->cash ?? 0);
            $cheque      = (float) ($c->cheque ?? 0);
            $bankDeposit = (float) ($c->bank_deposit ?? 0);
            $cards       = (float) ($c->cards ?? 0);
            $credit      = (float) ($creditSales->get($date)->credit ?? 0);
            $totalSales  = $cash + $credit + $cheque + $bankDeposit + $cards;

            $rows[] = [
                'date'         => $date,
                'cash'         => $cash,
                'credit'       => $credit,
                'cheque'       => $cheque,
                'bank_deposit' => $bankDeposit,
                'cards'        => $cards,
                'total_sales'  => $totalSales,
            ];

            $totals['cash']         += $cash;
            $totals['credit']       += $credit;
            $totals['cheque']       += $cheque;
            $totals['bank_deposit'] += $bankDeposit;
            $totals['cards']        += $cards;
            $totals['total_sales']  += $totalSales;
        }

        return [
            'header'  => $this->buildHeader($dateFrom, $dateTo, $totals, (int) $billStats->bill_count, (float) $billStats->net_sale),
            'rows'    => $rows,
            'summary' => $totals,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?string $dateFrom, ?string $dateTo, array $summary, int $billCount, float $netSale): array
    {
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
            'total_sales'     => $summary['total_sales'],
            // Bill count + invoiced revenue for the period (independent of collection).
            'bill_count'      => $billCount,
            'net_sale'        => $netSale,
            // Money actually collected for the period, split cash vs. everything else —
            // Non-Cash Sales rolls Cheque + Bank Deposit + Cards together.
            'cash_sale'       => $summary['cash'],
            'non_cash_sale'   => $summary['cheque'] + $summary['bank_deposit'] + $summary['cards'],
            'credit_sale'     => $summary['credit'],
            'generated_by'    => Auth::user()?->name,
            'generated_at'    => now()->toDateTimeString(),
        ];
    }
}
