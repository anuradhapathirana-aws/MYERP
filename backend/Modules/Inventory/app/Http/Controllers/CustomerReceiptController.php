<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CustomerReceiptData;
use Modules\Inventory\Http\Requests\StoreCustomerReceiptRequest;
use Modules\Inventory\Http\Requests\UpdateCustomerReceiptRequest;
use Modules\Inventory\Http\Resources\CustomerCreditNoteResource;
use Modules\Inventory\Http\Resources\CustomerReceiptResource;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Services\CustomerReceiptService;

class CustomerReceiptController extends Controller
{
    public function __construct(private readonly CustomerReceiptService $service)
    {
        $this->middleware('permission:view_customer_receipts')->only(['index', 'show', 'nextReceiptNo', 'outstandingInvoices', 'openCreditNotes']);
        $this->middleware('permission:create_customer_receipts')->only(['store']);
        $this->middleware('permission:edit_customer_receipts')->only(['update']);
        $this->middleware('permission:confirm_customer_receipts')->only(['confirm']);
        $this->middleware('permission:delete_customer_receipts')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => CustomerReceiptResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreCustomerReceiptRequest $request): JsonResponse
    {
        $receipt = $this->service->create(CustomerReceiptData::fromRequest($request));

        return response()->json(
            ['data' => (new CustomerReceiptResource($receipt))->toArray($request)],
            201,
        );
    }

    public function show(CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->service->find($customerReceipt->id);

        return response()->json(
            ['data' => (new CustomerReceiptResource($receipt))->toArray(request())],
        );
    }

    public function update(UpdateCustomerReceiptRequest $request, CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->service->update($customerReceipt, CustomerReceiptData::fromRequest($request));

        return response()->json(
            ['data' => (new CustomerReceiptResource($receipt))->toArray($request)],
        );
    }

    public function destroy(CustomerReceipt $customerReceipt): JsonResponse
    {
        $this->service->delete($customerReceipt);

        return response()->json(null, 204);
    }

    /** POST /customer-receipts/{receipt}/confirm */
    public function confirm(CustomerReceipt $customerReceipt): JsonResponse
    {
        $receipt = $this->service->confirm($customerReceipt);

        return response()->json(
            ['data' => (new CustomerReceiptResource($receipt))->toArray(request())],
        );
    }

    /** GET /customer-receipts/next-receipt-no — lock-free preview of next receipt number */
    public function nextReceiptNo(): JsonResponse
    {
        return response()->json(['data' => ['receipt_no' => $this->service->nextReceiptNo()]]);
    }

    /** GET /customer-receipts/outstanding-invoices/{customerId} */
    public function outstandingInvoices(int $customerId): JsonResponse
    {
        return response()->json(['data' => $this->service->getOutstandingInvoicesForCustomer($customerId)]);
    }

    /** GET /customer-receipts/open-credit-notes?customer_id=X&type=over_payment */
    public function openCreditNotes(Request $request): JsonResponse
    {
        $customerId = (int) $request->query('customer_id');
        $type       = $request->query('type');

        $creditNotes = $this->service->getOpenCreditNotesForCustomer($customerId, $type);

        return response()->json(['data' => CustomerCreditNoteResource::collection($creditNotes)]);
    }
}
