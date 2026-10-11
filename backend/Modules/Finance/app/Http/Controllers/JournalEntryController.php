<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\JournalEntryData;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\JournalType;
use Modules\Finance\Http\Requests\JournalEntryRequest;
use Modules\Finance\Http\Resources\JournalEntryResource;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\JournalEntryService;
use Modules\Finance\Services\JournalPostingService;

/**
 * The Journal Voucher screen and the journal list.
 *
 * Note the absent action: there is no update(). A posted journal is immutable,
 * and a draft is re-created rather than edited, so no route exists that could
 * rewrite an entry already in the books.
 */
class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryService   $service,
        private readonly JournalPostingService $posting,
    ) {
        $this->middleware('permission:view_journal_entries')
            ->only(['index', 'show', 'nextNo', 'options']);
        $this->middleware('permission:create_journal_entries')->only(['store']);
        $this->middleware('permission:post_journal_entries')->only(['post']);
        $this->middleware('permission:reverse_journal_entries')->only(['reverse']);
        $this->middleware('permission:delete_journal_entries')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters = request()->only([
            'search', 'journal_type', 'status', 'company_id',
            'location_id', 'date_from', 'date_to', 'source_type',
        ]);

        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => JournalEntryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->service->find($journalEntry)->load(['reverses', 'reversedBy']);

        return response()->json(['data' => (new JournalEntryResource($entry))->toArray(request())]);
    }

    /** The dropdown values the form needs, served from the enums. */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'journal_types' => JournalType::selectableOptions(),
                'statuses'      => JournalStatus::options(),
                'party_types'   => [
                    ['value' => 'supplier', 'label' => 'Supplier'],
                    ['value' => 'customer', 'label' => 'Customer'],
                    ['value' => 'employee', 'label' => 'Employee'],
                ],
                'posting_mode' => $this->posting->postingMode(),
            ],
        ]);
    }

    /** Lock-free preview of the number this voucher would receive. */
    public function nextNo(): JsonResponse
    {
        $date = request()->query('journal_date');

        return response()->json([
            'data' => [
                'journal_no' => $this->posting->previewNextJournalNo(
                    is_string($date) && $date !== '' ? new \DateTimeImmutable($date) : null,
                ),
            ],
        ]);
    }

    public function store(JournalEntryRequest $request): JsonResponse
    {
        $entry = $this->service->post(JournalEntryData::fromRequest($request));

        return response()->json(['data' => (new JournalEntryResource($entry))->toArray($request)], 201);
    }

    /** Promote a draft to posted -- the review-mode workflow. */
    public function post(JournalEntry $journalEntry): JsonResponse
    {
        $entry = $this->service->postDraft($journalEntry);

        return response()->json(['data' => (new JournalEntryResource($entry))->toArray(request())]);
    }

    /**
     * Post the mirror image of this journal.
     *
     * The only way to undo something already in the books. The original is left
     * exactly as it was and both continue to count, so the pair nets to zero.
     */
    public function reverse(JournalEntry $journalEntry): JsonResponse
    {
        $narration = request()->input('narration');

        $reversal = $this->service->reverse(
            $journalEntry,
            is_string($narration) && trim($narration) !== '' ? trim($narration) : null,
        );

        return response()->json(['data' => (new JournalEntryResource($reversal))->toArray(request())], 201);
    }

    /** Drafts only. The service refuses anything posted. */
    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->service->delete($journalEntry);

        return response()->json(null, 204);
    }
}
