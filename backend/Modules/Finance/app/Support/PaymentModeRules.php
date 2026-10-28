<?php

declare(strict_types=1);

namespace Modules\Finance\Support;

use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use Illuminate\Database\Eloquent\Builder;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\LedgerAccount;

/**
 * What a payment mode means for a Finance payment.
 *
 * Driven by the mode's fixed TYPE, never its free-text name or code, so a
 * client-added mode ("CEFT", "SLIPS") behaves correctly with no code change.
 * The same split as Inventory's Supplier Payment:
 *
 *   - Cash          : paid from a Cash Book
 *   - Cheque        : drawn on a Bank account; needs cheque no + date
 *   - Bank Transfer : from a Bank account; needs a reference no
 *   - Card          : charged to a Bank account
 *   - Set-off       : not a payment of money; refused (no advances in v1)
 */
final class PaymentModeRules
{
    /** Active modes a payment may use, in the master's order. */
    public static function usable(): Builder
    {
        return PaymentMode::query()
            ->where('is_active', true)
            ->where(static fn (Builder $q) => $q
                ->whereNull('type')->where('code', '!=', 'setoff')
                ->orWhere('type', '!=', PaymentModeType::Setoff->value))
            ->orderBy('sort_order')
            ->orderBy('payment_mode_name');
    }

    public static function isUsable(PaymentMode $mode): bool
    {
        return $mode->is_active
            && ! $mode->trashed()
            && $mode->effectiveType() !== PaymentModeType::Setoff;
    }

    public static function paysFrom(PaymentMode $mode): CashBookType
    {
        return $mode->effectiveType() === PaymentModeType::Cash ? CashBookType::CashBook : CashBookType::Bank;
    }

    public static function isCheque(PaymentMode $mode): bool
    {
        return $mode->effectiveType() === PaymentModeType::Cheque;
    }

    /** A transfer always needs the bank's reference; other modes when the master says so. */
    public static function needsReference(PaymentMode $mode): bool
    {
        return $mode->effectiveType() === PaymentModeType::BankTransfer
            || ($mode->requires_reference_no && ! self::isCheque($mode) && $mode->effectiveType() !== PaymentModeType::Cash);
    }

    public static function accountSuits(PaymentMode $mode, LedgerAccount $account): bool
    {
        return $account->cash_book_type === self::paysFrom($mode);
    }

    public static function wrongAccountMessage(PaymentMode $mode): string
    {
        return self::paysFrom($mode) === CashBookType::CashBook
            ? $mode->payment_mode_name . ' payments come from a Cash Book. Choose a Cash Book account.'
            : $mode->payment_mode_name . ' payments come from a Bank account. Choose a Bank account.';
    }
}
