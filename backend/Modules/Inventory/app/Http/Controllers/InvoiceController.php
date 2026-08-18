<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\InvoiceData;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Http\Requests\StoreInvoiceRequest;
use Modules\Inventory\Http\Requests\UpdateInvoiceRequest;
use Modules\Inventory\Http\Resources\InvoiceResource;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $service)
    {
        $this->middleware('permission:view_invoices')->only(['index', 'show', 'paymentHistory']);
        $this->middleware('permission:create_invoices')
            ->only(['store', 'nextInvoiceNo', 'billingSourceForSo', 'billingSourceForDo']);
        $this->middleware('permission:edit_invoices')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_invoices')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'so_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => InvoiceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->service->create(InvoiceData::fromRequest($request));

        return response()->json(['data' => new InvoiceResource($invoice)], 201);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => new InvoiceResource($this->service->find($invoice->id))]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $updated = $this->service->update($invoice, InvoiceData::fromRequest($request));

        return response()->json(['data' => new InvoiceResource($updated)]);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->service->delete($invoice);

        return response()->json(null, 204);
    }

    /** PATCH /invoices/{invoice}/status — issued | paid | cancelled */
    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'string']]);

        $updated = $this->service->updateStatus($invoice, $validated['status']);

        return response()->json(['data' => new InvoiceResource($updated)]);
    }

    /** GET /invoices/next-invoice-no?do_id=&invoice_date= — lock-free preview */
    public function nextInvoiceNo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->nextInvoiceNo(
            $request->integer('do_id') ?: null,
            $request->query('invoice_date'),
        )]);
    }

    /** GET /invoices/billing-source/so/{soId} — direct-SO billing preview */
    public function billingSourceForSo(int $soId): JsonResponse
    {
        return response()->json(['data' => $this->service->billingSourceForSo($soId)]);
    }

    /** GET /invoices/billing-source/do/{doId} — per-DO billing preview */
    public function billingSourceForDo(int $doId): JsonResponse
    {
        return response()->json(['data' => $this->service->billingSourceForDo($doId)]);
    }

    /**
     * GET /invoices/{invoice}/payment-history — the confirmed-receipt payoff trail
     * for one invoice, oldest first, each with its Receipt Details (how it was
     * actually paid — mode, amount, reference, bank). Draft receipts are excluded —
     * they haven't actually reduced the balance yet (CustomerReceiptService only
     * counts confirmed allocations toward outstanding).
     */
    public function paymentHistory(Invoice $invoice): JsonResponse
    {
        $allocations = DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('a.reference_type', 'invoice')
            ->where('a.reference_id', $invoice->id)
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->orderBy('r.receipt_date')
            ->orderBy('r.id')
            ->select(['r.id as receipt_id', 'r.receipt_no', 'r.receipt_date', 'a.receipt_amount', 'a.discount', 'a.outstanding_before'])
            ->get();

        $receiptIds = $allocations->pluck('receipt_id')->unique()->all();

        $settlementsByReceipt = DB::table('inv_customer_receipt_settlements')
            ->whereIn('receipt_id', $receiptIds)
            ->orderBy('id')
            ->get([
                'receipt_id', 'payment_mode_name', 'amount',
                'bank_name', 'bank_account_no', 'reference_no', 'instrument_date', 'remark',
            ])
            ->groupBy('receipt_id')
            ->map(fn ($group) => $group->map(fn ($s) => [
                'mode'            => $s->payment_mode_name,
                'amount'          => (float) $s->amount,
                'bank_name'       => $s->bank_name,
                'bank_account_no' => $s->bank_account_no,
                'reference_no'    => $s->reference_no,
                'instrument_date' => $s->instrument_date,
                'remark'          => $s->remark,
            ])->values());

        $rows = $allocations->map(fn ($row) => [
            'receipt_id'         => $row->receipt_id,
            'receipt_no'         => $row->receipt_no,
            'receipt_date'       => $row->receipt_date,
            'receipt_amount'     => (float) $row->receipt_amount,
            'discount'           => (float) $row->discount,
            'outstanding_before' => (float) $row->outstanding_before,
            'outstanding_after'  => (float) $row->outstanding_before - (float) $row->receipt_amount - (float) $row->discount,
            'settlements'        => $settlementsByReceipt->get($row->receipt_id, collect())->values(),
        ]);

        return response()->json(['data' => $rows]);
    }
}
