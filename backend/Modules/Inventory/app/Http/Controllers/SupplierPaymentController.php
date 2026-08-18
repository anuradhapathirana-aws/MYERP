<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\SupplierPaymentData;
use Modules\Inventory\Http\Requests\StoreSupplierPaymentRequest;
use Modules\Inventory\Http\Requests\UpdateSupplierPaymentRequest;
use Modules\Inventory\Http\Resources\SupplierCreditNoteResource;
use Modules\Inventory\Http\Resources\SupplierPaymentResource;
use Modules\Inventory\Models\SupplierPayment;
use Modules\Inventory\Services\SupplierPaymentService;

class SupplierPaymentController extends Controller
{
    public function __construct(private readonly SupplierPaymentService $service)
    {
        $this->middleware('permission:view_supplier_payments')->only(['index', 'show', 'nextPaymentNo', 'outstandingGrns', 'openCreditNotes']);
        $this->middleware('permission:create_supplier_payments')->only(['store']);
        $this->middleware('permission:edit_supplier_payments')->only(['update']);
        $this->middleware('permission:confirm_supplier_payments')->only(['confirm']);
        $this->middleware('permission:delete_supplier_payments')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'supplier_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => SupplierPaymentResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $payment = $this->service->create(SupplierPaymentData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierPaymentResource($payment))->toArray($request)],
            201,
        );
    }

    public function show(SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->service->find($supplierPayment->id);

        return response()->json(
            ['data' => (new SupplierPaymentResource($payment))->toArray(request())],
        );
    }

    public function update(UpdateSupplierPaymentRequest $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->service->update($supplierPayment, SupplierPaymentData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierPaymentResource($payment))->toArray($request)],
        );
    }

    public function destroy(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->service->delete($supplierPayment);

        return response()->json(null, 204);
    }

    /** POST /supplier-payments/{payment}/confirm */
    public function confirm(SupplierPayment $supplierPayment): JsonResponse
    {
        $payment = $this->service->confirm($supplierPayment);

        return response()->json(
            ['data' => (new SupplierPaymentResource($payment))->toArray(request())],
        );
    }

    /** GET /supplier-payments/next-payment-no — lock-free preview of next payment number */
    public function nextPaymentNo(): JsonResponse
    {
        return response()->json(['data' => ['payment_no' => $this->service->nextPaymentNo()]]);
    }

    /** GET /supplier-payments/outstanding-grns/{supplierId} */
    public function outstandingGrns(int $supplierId): JsonResponse
    {
        return response()->json(['data' => $this->service->getOutstandingGrnsForSupplier($supplierId)]);
    }

    /** GET /supplier-payments/open-credit-notes?supplier_id=X&type=over_payment */
    public function openCreditNotes(Request $request): JsonResponse
    {
        $supplierId = (int) $request->query('supplier_id');
        $type       = $request->query('type');

        $creditNotes = $this->service->getOpenCreditNotesForSupplier($supplierId, $type);

        return response()->json(['data' => SupplierCreditNoteResource::collection($creditNotes)]);
    }
}
