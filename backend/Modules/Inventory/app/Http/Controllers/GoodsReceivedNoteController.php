<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\GoodsReceivedNoteData;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Http\Requests\StoreGoodsReceivedNoteRequest;
use Modules\Inventory\Http\Requests\UpdateGoodsReceivedNoteRequest;
use Modules\Inventory\Http\Resources\GoodsReceivedNoteResource;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Services\GoodsReceivedNoteService;

class GoodsReceivedNoteController extends Controller
{
    public function __construct(private readonly GoodsReceivedNoteService $service)
    {
        $this->middleware('permission:view_grns')->only(['index', 'show', 'all', 'poOutstandingItems', 'poOutstandingItemsMultiple', 'nextGrnNo', 'lastGrn', 'lastProductPrices', 'checkShippingCode']);
        $this->middleware('permission:create_grns')->only(['store']);
        $this->middleware('permission:edit_grns')->only(['update']);
        $this->middleware('permission:confirm_grns')->only(['confirm']);
        $this->middleware('permission:delete_grns')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'store_id', 'supplier_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => GoodsReceivedNoteResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreGoodsReceivedNoteRequest $request): JsonResponse
    {
        $grn = $this->service->create(GoodsReceivedNoteData::fromRequest($request));

        return response()->json(
            ['data' => (new GoodsReceivedNoteResource($grn))->toArray($request)],
            201,
        );
    }

    public function show(GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        $grn = $this->service->find($goodsReceivedNote->id);

        return response()->json(
            ['data' => (new GoodsReceivedNoteResource($grn))->toArray(request())],
        );
    }

    public function update(UpdateGoodsReceivedNoteRequest $request, GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        $grn = $this->service->update($goodsReceivedNote, GoodsReceivedNoteData::fromRequest($request));

        return response()->json(
            ['data' => (new GoodsReceivedNoteResource($grn))->toArray($request)],
        );
    }

    public function destroy(GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        $this->service->delete($goodsReceivedNote);

        return response()->json(null, 204);
    }

    /** POST /goods-received-notes/{grn}/confirm — post stock to inventory */
    public function confirm(GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        $grn = $this->service->confirm($goodsReceivedNote);

        return response()->json(
            ['data' => (new GoodsReceivedNoteResource($grn))->toArray(request())],
        );
    }

    /** GET /goods-received-notes/po-items/{po} — outstanding PO items for GRN form */
    public function poOutstandingItems(int $poId): JsonResponse
    {
        $items = $this->service->getPoOutstandingItems($poId);

        return response()->json(['data' => $items]);
    }

    /** GET /goods-received-notes/po-items-multi?po_ids[]=1&po_ids[]=2 */
    public function poOutstandingItemsMultiple(Request $request): JsonResponse
    {
        $poIds = array_map('intval', (array) $request->input('po_ids', []));
        $items = $this->service->getPoOutstandingItemsForMultiple($poIds);

        return response()->json(['data' => $items]);
    }

    /** GET /goods-received-notes/next-grn-no — lock-free preview of next GRN number */
    public function nextGrnNo(): JsonResponse
    {
        return response()->json(['data' => ['grn_no' => $this->service->nextGrnNo()]]);
    }

    /** GET /goods-received-notes/check-shipping-code?shipping_code=X&exclude_id=Y — realtime uniqueness check */
    public function checkShippingCode(Request $request): JsonResponse
    {
        $shippingCode = trim((string) $request->query('shipping_code', ''));
        $excludeId    = $request->filled('exclude_id') ? (int) $request->query('exclude_id') : null;

        return response()->json([
            'data' => [
                'available' => $shippingCode !== '' && $this->service->isShippingCodeAvailable($shippingCode, $excludeId),
            ],
        ]);
    }

    /** GET /goods-received-notes/last — latest confirmed GRN system-wide */
    public function lastGrn(): JsonResponse
    {
        return response()->json(['data' => $this->service->lastGrn()]);
    }

    /** GET /goods-received-notes/last-product-prices?product_ids[]=1&product_ids[]=2 */
    public function lastProductPrices(Request $request): JsonResponse
    {
        $productIds = array_map('intval', (array) $request->input('product_ids', []));
        $prices     = $this->service->lastProductPrices($productIds);

        return response()->json(['data' => $prices]);
    }

    /** Lightweight list for dropdowns — id, grn_no only. Confirmed GRNs only. */
    public function all(): JsonResponse
    {
        $grns = GoodsReceivedNote::where('status', GrnStatus::Confirmed->value)
            ->orderByDesc('grn_no')
            ->get(['id', 'grn_no']);

        return response()->json(['data' => $grns]);
    }
}
