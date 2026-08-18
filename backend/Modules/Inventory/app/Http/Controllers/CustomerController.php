<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CustomerData;
use Modules\Inventory\Http\Requests\CustomerRequest;
use Modules\Inventory\Http\Resources\CustomerResource;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Services\CustomerService;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service)
    {
        $this->middleware('permission:view_customer_masters')->only(['index', 'show', 'all', 'nextCustomerCode']);
        $this->middleware('permission:create_customer_masters')->only(['store']);
        $this->middleware('permission:edit_customer_masters')->only(['update']);
        $this->middleware('permission:delete_customer_masters')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'customer_type', 'billing_city', 'billing_country', 'customer_code', 'mobile', 'email']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (CustomerMaster $item) => (new CustomerResource($item))->toArray(request()))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $customer = $this->service->create(CustomerData::fromRequest($request));
        $customer->load('attachmentFiles');

        return response()->json(
            ['data' => (new CustomerResource($customer))->toArray(request())],
            201,
        );
    }

    public function show(CustomerMaster $customerMaster): JsonResponse
    {
        $customerMaster->load('attachmentFiles');

        return response()->json(
            ['data' => (new CustomerResource($customerMaster))->toArray(request())],
        );
    }

    public function update(CustomerRequest $request, CustomerMaster $customerMaster): JsonResponse
    {
        $customer = $this->service->update($customerMaster, CustomerData::fromRequest($request));
        $customer->load('attachmentFiles');

        return response()->json(
            ['data' => (new CustomerResource($customer))->toArray(request())],
        );
    }

    public function destroy(CustomerMaster $customerMaster): JsonResponse
    {
        $this->service->delete($customerMaster);

        return response()->json(null, 204);
    }

    /** Preview the next auto-generated customer code (display only, non-locking). */
    public function nextCustomerCode(): JsonResponse
    {
        return response()->json(['data' => ['customer_code' => $this->service->nextCustomerCode()]]);
    }

    /** Flat list for <select> dropdowns — no pagination, lookup fields only. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (CustomerMaster $c) => [
                'id'            => $c->id,
                'name'          => $c->customer_name,
                'customer_code' => $c->customer_code,
                'customer_type' => $c->customer_type,
                'shipping_address' => implode(', ', array_filter([
                    $c->shipping_address_line1,
                    $c->shipping_address_line2,
                    $c->shipping_address_line3,
                    $c->shipping_city,
                    $c->shipping_state_province,
                    $c->shipping_zip_postal,
                    $c->shipping_country,
                ])),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
