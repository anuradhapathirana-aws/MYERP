<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\ProformaInvoiceData;
use Modules\Inventory\Http\Requests\StoreProformaInvoiceRequest;
use Modules\Inventory\Http\Requests\UpdateProformaInvoiceRequest;
use Modules\Inventory\Http\Resources\ProformaInvoiceResource;
use Modules\Inventory\Models\ProformaInvoice;
use Modules\Inventory\Services\ProformaInvoiceService;

class ProformaInvoiceController extends Controller
{
    public function __construct(private readonly ProformaInvoiceService $service)
    {
        $this->middleware('permission:view_proforma_invoices')->only(['index', 'show']);
        $this->middleware('permission:create_proforma_invoices')->only(['store', 'nextProformaNo', 'stockCheck', 'pricePreview']);
        // Recalling a proforma creates a sales order
        $this->middleware('permission:create_sales_orders')->only(['recallSource']);
        $this->middleware('permission:edit_proforma_invoices')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_proforma_invoices')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => ProformaInvoiceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreProformaInvoiceRequest $request): JsonResponse
    {
        $proforma = $this->service->create(ProformaInvoiceData::fromRequest($request));

        return response()->json(['data' => new ProformaInvoiceResource($proforma)], 201);
    }

    public function show(ProformaInvoice $proformaInvoice): JsonResponse
    {
        return response()->json(['data' => new ProformaInvoiceResource($this->service->find($proformaInvoice->id))]);
    }

    public function update(UpdateProformaInvoiceRequest $request, ProformaInvoice $proformaInvoice): JsonResponse
    {
        $updated = $this->service->update($proformaInvoice, ProformaInvoiceData::fromRequest($request));

        return response()->json(['data' => new ProformaInvoiceResource($updated)]);
    }

    public function destroy(ProformaInvoice $proformaInvoice): JsonResponse
    {
        $this->service->delete($proformaInvoice);

        return response()->json(null, 204);
    }

    /** PATCH /proforma-invoices/{proforma_invoice}/status — issued | cancelled */
    public function updateStatus(Request $request, ProformaInvoice $proformaInvoice): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'string', 'in:issued,cancelled']]);

        $updated = $this->service->updateStatus($proformaInvoice, $validated['status']);

        return response()->json(['data' => new ProformaInvoiceResource($updated)]);
    }

    /** GET /proforma-invoices/next-no?customer_id=&proforma_date= — lock-free preview */
    public function nextProformaNo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->nextProformaNo(
            $request->integer('customer_id') ?: null,
            $request->query('proforma_date'),
        )]);
    }

    /** POST /proforma-invoices/stock-check — live In stock / Partial / Non-stock per form line */
    public function stockCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines'                => ['required', 'array', 'max:200'],
            'lines.*.product_id'   => ['required', 'integer', 'exists:inv_products,id'],
            'lines.*.attribute_id' => ['nullable', 'integer'],
            'lines.*.unit_id'      => ['nullable', 'integer'],
            'lines.*.quantity'     => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $this->service->stockCheck($validated['lines'])]);
    }

    /** POST /proforma-invoices/price-preview — Non-Tax and Tax price sets per form line */
    public function pricePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines'                => ['required', 'array', 'max:200'],
            'lines.*.product_id'   => ['required', 'integer', 'exists:inv_products,id'],
            'lines.*.attribute_id' => ['nullable', 'integer'],
            'lines.*.unit_id'      => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->service->pricePreview($validated['lines'])]);
    }

    /** GET /proforma-invoices/{proforma_invoice}/recall — pre-fill for "Create SO from Proforma" */
    public function recallSource(ProformaInvoice $proformaInvoice): JsonResponse
    {
        return response()->json(['data' => $this->service->recallSource($proformaInvoice->id)]);
    }
}
