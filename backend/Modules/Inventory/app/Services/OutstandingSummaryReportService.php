<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\InvoiceStatus;

class OutstandingSummaryReportService
{
    /**
     * Hard ceiling on outstanding-invoice rows — this is a live snapshot (not a
     * date-bound ledger), but protects the JSON/PDF payload if the catalogue of
     * unpaid invoices ever grows unexpectedly large.
     */
    private const MAX_ROWS = 5000;

    /**
     * Build the Outstanding Summary Report: customer → each of their invoices that
     * still has an unpaid balance, with a per-customer subtotal and a grand total.
     * Shared by the JSON, PDF and CSV endpoints.
     *
     * Outstanding is not a stored column — invoices are never partially "Paid" in
     * status, only fully (CustomerReceiptService flips status to Paid once the
     * balance reaches zero). So this recomputes the same formula used to build the
     * receipt form's invoice picker (CustomerReceiptService::getOutstandingInvoicesForCustomer),
     * generalised across every customer instead of one:
     *
     *   outstanding = grand_total - SUM(confirmed receipt allocations' receipt_amount + discount)
     *
     * A discount on an allocation is a permanent write-off, so it reduces outstanding
     * exactly like received cash does. Only Issued invoices can have a balance —
     * Draft was never billed, Cancelled and Paid owe nothing.
     *
     * @param array{customer_id?:int|null, overdue_only?:bool} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $customerId  = !empty($filters['customer_id']) ? (int) $filters['customer_id'] : null;
        $overdueOnly = !empty($filters['overdue_only']);
        $today       = Carbon::today()->toDateString();

        $invoices = DB::table('inv_invoices as i')
            ->join('inv_customer_masters as cu', 'cu.id', '=', 'i.customer_id')
            ->leftJoin('inv_sales_orders as so', 'so.id', '=', 'i.so_id')
            ->leftJoin('inv_delivery_orders as do', 'do.id', '=', 'i.do_id')
            ->where('i.status', InvoiceStatus::Issued->value)
            ->whereNull('i.deleted_at')
            ->when($customerId, fn ($q) => $q->where('i.customer_id', $customerId))
            ->when($overdueOnly, fn ($q) => $q->whereNotNull('i.due_date')->where('i.due_date', '<', $today))
            ->orderBy('cu.customer_name')
            ->orderBy('i.invoice_date')
            ->select([
                'i.id', 'i.customer_id', 'cu.customer_name',
                'i.invoice_no', 'i.invoice_date', 'i.due_date',
                'i.grand_total', 'so.so_no', 'do.do_no',
            ])
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($invoices->count() > self::MAX_ROWS) {
            abort(422, 'Too many outstanding invoices to display — narrow the filters.');
        }

        $receivedByInvoice = $invoices->isEmpty()
            ? collect()
            : DB::table('inv_customer_receipt_allocations as a')
                ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
                ->whereIn('a.reference_id', $invoices->pluck('id')->all())
                ->where('a.reference_type', 'invoice')
                ->where('r.status', CustomerReceiptStatus::Confirmed->value)
                ->groupBy('a.reference_id')
                ->select('a.reference_id', DB::raw('SUM(a.receipt_amount + a.discount) as received'))
                ->get()
                ->pluck('received', 'reference_id');

        $customers      = [];
        $totalOutstanding = 0.0;
        $totalInvoices    = 0;

        foreach ($invoices->groupBy('customer_id') as $custId => $custInvoices) {
            $rows          = [];
            $customerTotal = 0.0;

            foreach ($custInvoices as $inv) {
                $amount      = (float) $inv->grand_total;
                $received    = (float) ($receivedByInvoice[$inv->id] ?? 0);
                $outstanding = $amount - $received;

                if ($outstanding <= 0.01) {
                    continue;
                }

                $daysOverdue = $inv->due_date
                    ? Carbon::parse($inv->due_date)->diffInDays(Carbon::parse($today), false)
                    : null;

                $rows[] = [
                    'invoice_id'    => $inv->id,
                    'invoice_no'    => $inv->invoice_no,
                    'invoice_date'  => $inv->invoice_date,
                    'due_date'      => $inv->due_date,
                    'days_overdue'  => $daysOverdue !== null ? max(0, (int) $daysOverdue) : null,
                    'so_no'         => $inv->so_no,
                    'do_no'         => $inv->do_no,
                    'amount'        => $amount,
                    'received'      => $received,
                    'outstanding'   => $outstanding,
                ];

                $customerTotal += $outstanding;
            }

            if (empty($rows)) {
                continue;
            }

            $customers[] = [
                'customer_id'         => $custId,
                'customer_name'       => $custInvoices->first()->customer_name,
                'invoices'            => $rows,
                'customer_outstanding' => $customerTotal,
                'invoice_count'       => count($rows),
            ];

            $totalOutstanding += $customerTotal;
            $totalInvoices    += count($rows);
        }

        $summary = [
            'total_outstanding' => $totalOutstanding,
            'total_invoices'    => $totalInvoices,
        ];

        return [
            'header'    => $this->buildHeader($customerId, $overdueOnly, $summary),
            'customers' => $customers,
            'summary'   => $summary,
        ];
    }

    /**
     * @param array<string, float|int> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?int $customerId, bool $overdueOnly, array $summary): array
    {
        $customer = $customerId ? DB::table('inv_customer_masters')->where('id', $customerId)->first(['customer_name']) : null;

        // Single-tenant deployment: the report always belongs to the one primary company.
        $company = DB::table('inv_companies')->orderBy('id')->first();

        return [
            'company_name'      => $company->company_name ?? null,
            'company_address'   => $company
                ? collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ')
                : null,
            'company_email'     => $company->company_email ?? null,
            'customer_name'     => $customer->customer_name ?? null,
            'overdue_only'      => $overdueOnly,
            'total_outstanding' => $summary['total_outstanding'],
            'total_invoices'    => $summary['total_invoices'],
            'as_of'             => Carbon::today()->toDateString(),
            'generated_by'      => Auth::user()?->name,
            'generated_at'      => now()->toDateTimeString(),
        ];
    }
}
