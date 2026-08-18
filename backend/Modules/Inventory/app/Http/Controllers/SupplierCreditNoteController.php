<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Http\Resources\SupplierCreditNoteResource;
use Modules\Inventory\Models\SupplierCreditNote;

class SupplierCreditNoteController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_supplier_credit_notes')->only(['index', 'show']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = SupplierCreditNote::orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', (int) $request->query('supplier_id'));
        }

        if ($request->filled('credit_type')) {
            $query->where('credit_type', $request->query('credit_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate(50);

        return response()->json([
            'data' => SupplierCreditNoteResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(SupplierCreditNote $supplierCreditNote): JsonResponse
    {
        return response()->json(
            ['data' => (new SupplierCreditNoteResource($supplierCreditNote))->toArray(request())],
        );
    }
}
