<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;

class LedgerAccountService
{
    /**
     * Loaded on every read: the list shows the full path (type > category >
     * control) and the bank details on each row. Without this it is four
     * queries per row.
     */
    private const EAGER = ['controlAccount.category', 'company', 'bank', 'bankBranch'];

    public function __construct(private readonly AccountCodeGenerator $codes) {}

    /**
     * Read cash_book_type as a list, however it arrived.
     *
     * "Offer cash AND bank in one request" is the common case -- a Supplier
     * Payment can be settled either way -- so a single scalar would make it
     * inexpressible. Accepts an array from PHP callers and a comma-separated
     * string from a query string, since ?cash_book_type[]=a&cash_book_type[]=b
     * is awkward for the frontend to build.
     *
     * @return list<string>|null null means "do not filter at all"
     */
    private static function cashBookTypes(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $list = is_array($value) ? $value : explode(',', (string) $value);

        $list = array_values(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            $list,
        ), static fn (string $t): bool => $t !== ''));

        return $list === [] ? null : $list;
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = LedgerAccount::with(self::EAGER)->orderBy('code');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('ledger_account_name', 'like', $term)->orWhere('code', 'like', $term));
        }

        if (! empty($filters['control_account_id'])) {
            $query->where('control_account_id', (int) $filters['control_account_id']);
        }

        if (! empty($filters['account_category_id'])) {
            $query->whereHas(
                'controlAccount',
                fn ($q) => $q->where('account_category_id', (int) $filters['account_category_id']),
            );
        }

        if (! empty($filters['account_type'])) {
            $query->whereHas(
                'controlAccount.category',
                fn ($q) => $q->where('account_type', $filters['account_type']),
            );
        }

        if (($types = self::cashBookTypes($filters['cash_book_type'] ?? null)) !== null) {
            $query->whereIn('cash_book_type', $types);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    /**
     * Flat list for dropdowns.
     *
     * `fund_sources_only` is what payment screens use: rather than offering the
     * entire chart of accounts, they ask for the handful of accounts money
     * actually moves through. Pass cash_book_type to narrow further — a Petty
     * Cash Payment wants petty_cash_book alone, while a Supplier Payment wants
     * cash and bank together, which is why it accepts a list.
     *
     * @param array<string, mixed> $filters
     */
    public function all(array $filters = []): Collection
    {
        return LedgerAccount::with(self::EAGER)
            ->where('is_active', true)
            // Per-party accounts are excluded by default: a chart with
            // thousands of customer accounts makes a dropdown unusable, and
            // documents resolve those automatically from the party on the
            // line, so hand-picking one is nearly always a mistake.
            ->when(
                empty($filters['include_party_accounts']),
                fn ($q) => $q->excludingParties(),
            )
            ->when(
                ! empty($filters['control_account_id']),
                fn ($q) => $q->where('control_account_id', (int) $filters['control_account_id']),
            )
            ->when(
                ! empty($filters['account_category_id']),
                fn ($q) => $q->whereHas('controlAccount', fn ($c) => $c->where('account_category_id', (int) $filters['account_category_id'])),
            )
            ->when(
                ! empty($filters['account_type']),
                fn ($q) => $q->whereHas('controlAccount.category', fn ($c) => $c->where('account_type', $filters['account_type'])),
            )
            ->when(
                ! empty($filters['fund_sources_only']),
                fn ($q) => $q->fundSources(
                    self::cashBookTypes($filters['cash_book_type'] ?? null),
                    // A payment made by one company may only draw on that
                    // company's own cash and bank accounts.
                    ! empty($filters['company_id']) ? (int) $filters['company_id'] : null,
                ),
            )
            ->when(
                empty($filters['fund_sources_only']) && ! empty($filters['company_id']),
                fn ($q) => $q->where('company_id', (int) $filters['company_id']),
            )
            ->when(
                empty($filters['fund_sources_only']) && ! empty($filters['cash_book_type']),
                fn ($q) => $q->where('cash_book_type', $filters['cash_book_type']),
            )
            ->orderBy('code')
            ->get();
    }

    public function create(LedgerAccountData $data): LedgerAccount
    {
        return DB::transaction(function () use ($data): LedgerAccount {
            $control = ControlAccount::findOrFail($data->control_account_id);

            $ledger = new LedgerAccount($this->toAttributes($data));
            $ledger->code = $this->codes->nextLedgerAccountCode($control, lock: true);
            $ledger->save();

            return $ledger->load(self::EAGER);
        });
    }

    /**
     * Create the ledger account that represents one supplier or customer.
     *
     * Separate from create() on purpose. That one takes a LedgerAccountData
     * built from a form, with a cash/bank block and a company that make no
     * sense for a party. This one is called by PartyLedgerProvisioner during a
     * posting, so it accepts only what a party account can legitimately have.
     *
     * The unique index on (party_type, party_id) is the real guarantee here:
     * two concurrent postings for the same new supplier cannot both succeed, so
     * a party can never end up with two accounts.
     */
    public function createForParty(
        int    $controlAccountId,
        string $name,
        string $partyType,
        int    $partyId,
    ): LedgerAccount {
        return DB::transaction(function () use ($controlAccountId, $name, $partyType, $partyId): LedgerAccount {
            $control = ControlAccount::findOrFail($controlAccountId);

            $ledger = new LedgerAccount([
                'control_account_id'  => $control->id,
                'ledger_account_name' => $name,
                'is_active'           => true,
                'party_type'          => $partyType,
                'party_id'            => $partyId,
            ]);

            $ledger->code = $this->codes->nextLedgerAccountCode($control, lock: true);
            $ledger->save();

            return $ledger;
        });
    }

    public function update(LedgerAccount $ledger, LedgerAccountData $data): LedgerAccount
    {
        // The control account's code is the first five digits of this one.
        if ($ledger->control_account_id !== $data->control_account_id) {
            throw ValidationException::withMessages([
                'control_account_id' => "The control account cannot be changed once a code is assigned. Code "
                    . "{$ledger->code} sits under control account {$ledger->controlAccount?->code}. Create a new "
                    . 'ledger account under the other control account instead.',
            ]);
        }

        $ledger->update($this->toAttributes($data));

        return $ledger->fresh()->load(self::EAGER);
    }

    /**
     * Soft delete, and never once an account has been posted to.
     *
     * This is the guard the earlier version of this comment asked for. A posted
     * account must not disappear: historical reports still have to resolve its
     * code and name, and fin_journal_lines.ledger_account_id carries
     * restrictOnDelete precisely so the database would refuse a hard delete.
     * Checking here turns that database error into a readable message.
     *
     * Deactivating is the right move for an account that should no longer be
     * used -- it stays resolvable for history while refusing new postings.
     */
    public function delete(LedgerAccount $ledger): void
    {
        $lines = JournalLine::where('ledger_account_id', $ledger->id)->count();

        if ($lines > 0) {
            throw ValidationException::withMessages([
                'id' => $ledger->code . ' ' . $ledger->ledger_account_name . ' has ' . $lines
                    . ' journal line(s) posted to it and can never be deleted. Set it inactive '
                    . 'instead -- history keeps resolving it, and nothing new can post to it.',
            ]);
        }

        $ledger->delete();
    }

    public function nextCode(ControlAccount $control): string
    {
        return $this->codes->nextLedgerAccountCode($control);
    }

    /** @return array<string, mixed> */
    private function toAttributes(LedgerAccountData $data): array
    {
        // LedgerAccountData has already cleared the fields belonging to whichever
        // cash/bank branch is not selected, so this is a straight copy.
        return [
            'control_account_id'  => $data->control_account_id,
            'ledger_account_name' => $data->ledger_account_name,
            'description'         => $data->description,
            'is_active'           => $data->is_active,
            'cash_book_type'      => $data->cash_book_type,
            'company_id'          => $data->company_id,
            'allows_cash'         => $data->allows_cash,
            'allows_cheque'       => $data->allows_cheque,
            'bank_id'             => $data->bank_id,
            'bank_branch_id'      => $data->bank_branch_id,
            'bank_account_no'     => $data->bank_account_no,
        ];
    }
}
