<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Enums\PartyLedgerMode;
use Modules\Finance\Http\Requests\AccountMappingRequest;
use Modules\Finance\Services\AccountMappingService;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Services\JournalPostingService;

/**
 * Finance > Settings: the account mapping and the posting mode.
 *
 * Gated by its own permission rather than by the chart-of-accounts ones. The
 * blast radius is different in kind: editing a ledger account affects that
 * account, while repointing accounts_payable affects every future GRN and
 * payment in the system. A separate permission means mapping rights can go to
 * a senior accountant without making them an administrator, or be withheld
 * from an administrator who should not have them.
 */
class FinanceSettingsController extends Controller
{
    public function __construct(
        private readonly AccountMappingService $service,
        private readonly FinanceControlService $controls,
    ) {
        $this->middleware('permission:view_finance_settings')->only(['index']);
        $this->middleware('permission:manage_finance_settings')
            ->only(['update', 'updatePostingMode', 'updatePartyLedgerMode', 'updateLockDate', 'updateMakerChecker']);
    }

    public function index(): JsonResponse
    {
        $companyId = request()->filled('company_id') ? (int) request()->input('company_id') : null;

        return response()->json([
            'data' => [
                'mappings'         => $this->service->all($companyId),
                'missing_required' => $this->service->missingRequired($companyId),
                'posting_mode'     => $this->service->postingMode(),

                // Period lock and maker-checker (FinanceControlService).
                'lock_date'        => $this->controls->lockDate()?->toDateString(),
                'maker_checker'    => $this->controls->makerCheckerEnabled(),

                // Which side of the chart keeps one account per party, and the
                // two choices with the reasoning behind each.
                'party_ledger_modes'   => $this->service->partyLedgerModes(),
                'party_ledger_options' => PartyLedgerMode::options(),
                'posting_modes'    => [
                    [
                        'value'       => JournalPostingService::MODE_REVIEW,
                        'label'       => 'Review first',
                        'description' => 'A confirmed document creates a DRAFT journal for finance to check '
                            . 'and post in a batch. Use this for the first month.',
                    ],
                    [
                        'value'       => JournalPostingService::MODE_IMMEDIATE,
                        'label'       => 'Post immediately',
                        'description' => 'A confirmed document posts straight to the ledger. Switch to this '
                            . 'once the mapping has been verified against real documents.',
                    ],
                ],
            ],
        ]);
    }

    public function update(AccountMappingRequest $request): JsonResponse
    {
        $companyId = $request->filled('company_id') ? (int) $request->input('company_id') : null;

        /** @var array<string, int|null> $mappings */
        $mappings = (array) $request->input('mappings', []);

        $this->service->update($mappings, $companyId);

        return response()->json([
            'data' => [
                'mappings'         => $this->service->all($companyId),
                'missing_required' => $this->service->missingRequired($companyId),
            ],
        ]);
    }

    public function updatePostingMode(): JsonResponse
    {
        $mode = $this->service->setPostingMode((string) request()->input('posting_mode'));

        return response()->json(['data' => ['posting_mode' => $mode]]);
    }

    /**
     * Set the last closed day, or clear it with null. Never in the future.
     * Moving it back reopens a period, which the audit trail records.
     */
    public function updateLockDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lock_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'lock_date.before_or_equal' => 'The lock date cannot be in the future.',
        ]);

        $date = $this->controls->setLockDate($validated['lock_date'] ?? null);

        return response()->json(['data' => ['lock_date' => $date?->toDateString()]]);
    }

    public function updateMakerChecker(Request $request): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json([
            'data' => ['maker_checker' => $this->controls->setMakerChecker((bool) $validated['enabled'])],
        ]);
    }

    public function updatePartyLedgerMode(): JsonResponse
    {
        $modes = $this->service->setPartyLedgerMode(
            (string) request()->input('party_type'),
            (string) request()->input('mode'),
        );

        return response()->json(['data' => ['party_ledger_modes' => $modes]]);
    }
}
