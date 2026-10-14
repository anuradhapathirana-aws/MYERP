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
        $this->middleware('permission:create_proforma_invoices')->only(['store', 'nextProformaNo', 'sourceForSo']);
        $this->middleware('permission:edit_proforma_invoices')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_proforma_invoices')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'so_id', 'date_from', 'date_to']);
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

    /** GET /proforma-invoices/next-no?so_id=&proforma_date= — lock-free preview */
    public function nextProformaNo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->nextProformaNo(
            $request->integer('so_id') ?: null,
            $request->query('proforma_date'),
        )]);
    }

    /** GET /proforma-invoices/source/so/{soId} — quote preview of a sales order */
    public function sourceForSo(int $soId): JsonResponse
    {
        return response()->json(['data' => $this->service->sourceForSo($soId)]);
    }
}
