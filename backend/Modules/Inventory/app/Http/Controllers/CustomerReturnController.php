<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CustomerReturnData;
use Modules\Inventory\Http\Requests\StoreCustomerReturnRequest;
use Modules\Inventory\Http\Requests\UpdateCustomerReturnRequest;
use Modules\Inventory\Http\Resources\CustomerReturnResource;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Services\CustomerReturnService;

class CustomerReturnController extends Controller
{
    public function __construct(private readonly CustomerReturnService $service)
    {
        $this->middleware('permission:view_customer_returns')->only(['index', 'show', 'nextReturnNo', 'returnableInvoices', 'returnableItems']);
        $this->middleware('permission:create_customer_returns')->only(['store']);
        $this->middleware('permission:edit_customer_returns')->only(['update']);
        $this->middleware('permission:confirm_customer_returns')->only(['confirm']);
        $this->middleware('permission:delete_customer_returns')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'invoice_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => CustomerReturnResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreCustomerReturnRequest $request): JsonResponse
    {
        $return = $this->service->create(CustomerReturnData::fromRequest($request));

        return response()->json(
            ['data' => (new CustomerReturnResource($return))->toArray($request)],
            201,
        );
    }

    public function show(CustomerReturn $customerReturn): JsonResponse
    {
        $return = $this->service->find($customerReturn->id);

        return response()->json(
            ['data' => (new CustomerReturnResource($return))->toArray(request())],
        );
    }

    public function update(UpdateCustomerReturnRequest $request, CustomerReturn $customerReturn): JsonResponse
    {
        $return = $this->service->update($customerReturn, CustomerReturnData::fromRequest($request));

        return response()->json(
            ['data' => (new CustomerReturnResource($return))->toArray($request)],
        );
    }

    public function destroy(CustomerReturn $customerReturn): JsonResponse
    {
        $this->service->delete($customerReturn);

        return response()->json(null, 204);
    }

    /** POST /customer-returns/{return}/confirm */
    public function confirm(CustomerReturn $customerReturn): JsonResponse
    {
        $return = $this->service->confirm($customerReturn);

        return response()->json(
            ['data' => (new CustomerReturnResource($return))->toArray(request())],
        );
    }

    /** GET /customer-returns/next-return-no — lock-free preview of the next return number */
    public function nextReturnNo(): JsonResponse
    {
        return response()->json(['data' => ['return_no' => $this->service->nextReturnNo()]]);
    }

    /** GET /customer-returns/returnable-invoices/{customerId} */
    public function returnableInvoices(int $customerId): JsonResponse
    {
        return response()->json(['data' => $this->service->returnableInvoices($customerId)]);
    }

    /** GET /customer-returns/returnable-items/{invoiceId} */
    public function returnableItems(int $invoiceId): JsonResponse
    {
        return response()->json(['data' => $this->service->returnableItems($invoiceId)]);
    }
}
