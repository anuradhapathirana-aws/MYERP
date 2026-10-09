<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Http\Requests\LedgerAccountRequest;

final class LedgerAccountData
{
    public function __construct(
        public readonly int           $control_account_id,
        public readonly string        $ledger_account_name,
        public readonly ?string       $description,
        public readonly bool          $is_active,
        public readonly ?CashBookType $cash_book_type,
        public readonly ?int          $company_id,
        public readonly bool          $allows_cash,
        public readonly bool          $allows_cheque,
        public readonly ?int          $bank_id,
        public readonly ?int          $bank_branch_id,
        public readonly ?string       $bank_account_no,
    ) {}

    public static function fromRequest(LedgerAccountRequest $request): self
    {
        $rawType = $request->input('cash_book_type');
        $type    = is_string($rawType) && $rawType !== '' ? CashBookType::from($rawType) : null;

        // Sub-fields are cleared unless their owning branch is selected, so an
        // account switched from Bank to Cash Book cannot keep a stale account
        // number that no screen displays any more. Normalising here rather than
        // in the service keeps the persisted row honest whatever the caller sent.
        $isCashBook = $type === CashBookType::CashBook;
        $isBank     = $type === CashBookType::Bank;

        return new self(
            control_account_id:  (int) $request->input('control_account_id'),
            ledger_account_name: trim((string) $request->input('ledger_account_name')),
            description:         self::nullableTrim($request->input('description')),
            is_active:           (bool) $request->input('is_active', true),
            cash_book_type:      $type,
            // The owning legal entity applies to every cash/bank branch, not
            // just the bank one — a cash box and a petty cash float belong to
            // one company too. Cleared when the account is an ordinary one.
            company_id:          $type !== null ? self::nullableInt($request->input('company_id')) : null,
            allows_cash:         $isCashBook && (bool) $request->input('allows_cash', false),
            allows_cheque:       $isCashBook && (bool) $request->input('allows_cheque', false),
            bank_id:             $isBank ? self::nullableInt($request->input('bank_id')) : null,
            bank_branch_id:      $isBank ? self::nullableInt($request->input('bank_branch_id')) : null,
            bank_account_no:     $isBank ? self::nullableTrim($request->input('bank_account_no')) : null,
        );
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
