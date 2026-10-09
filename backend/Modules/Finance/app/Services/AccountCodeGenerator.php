<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;

/**
 * Allocates the hierarchical account codes.
 *
 *   Account Type  1          (AccountType::digit)
 *   Category      101        type digit + 2-digit sequence
 *   Control       10101      category code + 2-digit sequence
 *   Ledger        10101001   control code  + 3-digit sequence
 *
 * Reading the code tells you the whole path, and sorting by code yields a
 * correctly ordered chart of accounts with no joins. That is why the code is
 * generated rather than typed, and why it is immutable once assigned.
 *
 * Every level is mandatory, so every ledger code is exactly 8 characters. That
 * fixed width is what keeps MAX() on a varchar numerically correct and ORDER BY
 * code meaningful.
 *
 * ── Concurrency ──────────────────────────────────────────────────────────────
 * Follows the same contract as inv_purchase_requests.reference_no (see the
 * Business Rules section of CLAUDE.md): callers pass $lock = true from inside
 * a transaction so two simultaneous saves cannot be handed the same code. The
 * UNIQUE index on every `code` column is the backstop if that is ever missed.
 *
 * The preview endpoints call with $lock = false — display only, never persisted.
 *
 * ── Why codes are never reused ───────────────────────────────────────────────
 * The next sequence comes from MAX(code), not COUNT(*), and the query runs
 * through the query builder so soft-deleted rows are still counted. Deleting
 * category 103 therefore leaves 104 as the next code. An account code that
 * appears in a printed journal must never be reassigned to a different account.
 */
final class AccountCodeGenerator
{
    private const CATEGORY_SEQUENCE_WIDTH = 2;
    private const CONTROL_SEQUENCE_WIDTH  = 2;
    private const LEDGER_SEQUENCE_WIDTH   = 3;

    public function nextCategoryCode(AccountType $type, bool $lock = false): string
    {
        return $this->next(
            table:    'fin_account_categories',
            prefix:   (string) $type->digit(),
            width:    self::CATEGORY_SEQUENCE_WIDTH,
            lock:     $lock,
            exhausted: "No account codes remain under {$type->label()}. "
                . 'Each account type supports 99 categories.',
        );
    }

    public function nextControlAccountCode(AccountCategory $category, bool $lock = false): string
    {
        return $this->next(
            table:    'fin_control_accounts',
            prefix:   $category->code,
            width:    self::CONTROL_SEQUENCE_WIDTH,
            lock:     $lock,
            exhausted: "No control account codes remain under category {$category->code} "
                . "({$category->category_name}). Each category supports 99 control accounts.",
        );
    }

    public function nextLedgerAccountCode(ControlAccount $control, bool $lock = false): string
    {
        return $this->next(
            table:    'fin_ledger_accounts',
            prefix:   $control->code,
            width:    self::LEDGER_SEQUENCE_WIDTH,
            lock:     $lock,
            exhausted: "No ledger account codes remain under control account {$control->code} "
                . "({$control->control_account_name}). Each control account supports 999 ledgers.",
        );
    }

    /**
     * Allocate the next `prefix` + zero-padded sequence not yet used in $table.
     *
     * @throws ValidationException when the sequence for this parent is exhausted.
     */
    private function next(string $table, string $prefix, int $width, bool $lock, string $exhausted): string
    {
        // DB::table() deliberately bypasses the SoftDeletes global scope: a
        // soft-deleted code is still a used code.
        $query = DB::table($table)->where('code', 'like', $prefix . '%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $highest = $query->max('code');

        // Codes are fixed-width and zero-padded, so MAX() on the varchar is the
        // numerically highest sibling.
        $sequence = $highest === null
            ? 1
            : ((int) substr((string) $highest, strlen($prefix))) + 1;

        if ($sequence > (10 ** $width) - 1) {
            throw ValidationException::withMessages(['code' => $exhausted]);
        }

        return $prefix . str_pad((string) $sequence, $width, '0', STR_PAD_LEFT);
    }
}
