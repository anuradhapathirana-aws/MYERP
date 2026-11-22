<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AmountPrecisionUsage;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How many decimal places money AMOUNTS carry, system-wide.
 *
 * Amounts are document totals, line amounts, tax, receipts, payments and
 * journal lines. Unit prices, unit costs, rates and quantities are not
 * amounts: they keep their own precision (Inventory's Quantity class), and an
 * amount calculated from them is rounded here when it is stored.
 *
 * ── Why it locks ─────────────────────────────────────────────────────────────
 * Changing precision after documents exist makes old documents disagree with
 * new ones and with the ledger. So the setting is chosen at setup and locks
 * as soon as any module reports a document (AmountPrecisionUsage). Existing
 * records are never rewritten.
 *
 * ── Storage, and why MAX is 2 ────────────────────────────────────────────────
 * Inventory amounts are decimal(15,4), but every Finance money column (bills,
 * payments, deposits, reconciliations), GRN tax lines and supplier credit
 * limits are decimal(15,2). A third decimal would be silently cut off there,
 * so MAX is 2 until those columns are widened by a migration. 0 and 1 are
 * allowed (currencies without cents).
 *
 * Registered as a singleton: decimals() is read on every amount, so it is
 * memoised for the request on top of SettingsService's cache.
 */
final class AmountPrecision
{
    public const SETTING_KEY = 'system.amount_decimals';
    public const DEFAULT     = 2;
    public const MIN         = 0;
    public const MAX         = 2;

    private ?int $decimals = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function decimals(): int
    {
        if ($this->decimals === null) {
            $value          = (int) $this->settings->get(self::SETTING_KEY, self::DEFAULT);
            $this->decimals = max(self::MIN, min(self::MAX, $value));
        }

        return $this->decimals;
    }

    /** Why the setting can no longer change, or null while it still can. */
    public function lockReason(): ?string
    {
        $reasons = [];

        foreach (app()->tagged(AmountPrecisionUsage::TAG) as $usage) {
            $reason = $usage->usedBy();

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        return $reasons === [] ? null : 'Documents already exist: ' . implode(', ', $reasons) . '.';
    }

    /** @return array{decimals: int, min: int, max: int, locked: bool, lock_reason: ?string} */
    public function status(): array
    {
        $reason = $this->lockReason();

        return [
            'decimals'    => $this->decimals(),
            'min'         => self::MIN,
            'max'         => self::MAX,
            'locked'      => $reason !== null,
            'lock_reason' => $reason,
        ];
    }

    /**
     * Change the setting (super admin, before any document exists) and audit it.
     *
     * @throws ValidationException when locked or out of range
     */
    public function update(int $decimals): array
    {
        if ($decimals < self::MIN || $decimals > self::MAX) {
            throw ValidationException::withMessages([
                'decimals' => 'Choose between ' . self::MIN . ' and ' . self::MAX . ' decimal places.',
            ]);
        }

        $before = $this->decimals();

        if ($decimals === $before) {
            return $this->status();
        }

        $reason = $this->lockReason();

        if ($reason !== null) {
            throw ValidationException::withMessages([
                'decimals' => 'Amount decimals are locked. ' . $reason
                    . ' Changing precision now would make old documents disagree with new ones.',
            ]);
        }

        DB::transaction(function () use ($before, $decimals): void {
            $this->settings->set(self::SETTING_KEY, $decimals);

            $setting = GlobalSetting::where('key', self::SETTING_KEY)->firstOrFail();

            $this->audit->record(
                'global_setting',
                (int) $setting->id,
                'amount_decimals_updated',
                ['decimals' => $before],
                ['decimals' => $decimals],
            );
        });

        $this->decimals = $decimals;

        return $this->status();
    }
}
