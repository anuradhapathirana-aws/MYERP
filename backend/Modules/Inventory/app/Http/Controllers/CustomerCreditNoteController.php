<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Http\Resources\CustomerCreditNoteResource;
use Modules\Inventory\Models\CustomerCreditNote;

class CustomerCreditNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_customer_credit_notes')->only(['index', 'show']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = CustomerCreditNote::orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }

        if ($request->filled('credit_type')) {
            $query->where('credit_type', $request->query('credit_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate(50);

        return response()->json([
            'data' => CustomerCreditNoteResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(CustomerCreditNote $customerCreditNote): JsonResponse
    {
        return response()->json(
            ['data' => (new CustomerCreditNoteResource($customerCreditNote))->toArray(request())],
        );
    }
}
