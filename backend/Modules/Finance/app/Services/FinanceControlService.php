<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * The period lock and the maker-checker switch.
 *
 * ── Period lock ──────────────────────────────────────────────────────────────
 * Once a month is closed and reported (VAT return filed, P&L handed over), its
 * numbers must never move again. finance.lock_date is the last CLOSED day:
 * nothing may be posted or reversed on or before it. Null = nothing locked.
 *
 * Enforced inside JournalPostingService, so it protects every document that
 * posts -- Bills, vouchers, and Inventory's GRN / Invoice / Payment alike --
 * rather than relying on each screen to remember.
 *
 * The lock can never be set in the future. That keeps "today" always open,
 * which is what lets existing voids that reverse on today's date keep working.
 *
 * ── Maker-checker ────────────────────────────────────────────────────────────
 * Off by default so a one-accountant client can still work. When on, the
 * person who entered a document may not also confirm it.
 */
class FinanceControlService
{
    public const SETTING_LOCK_DATE     = 'finance.lock_date';
    public const SETTING_MAKER_CHECKER = 'finance.maker_checker';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger     $audit,
    ) {}

    public function lockDate(): ?CarbonImmutable
    {
        $value = $this->settings->get(self::SETTING_LOCK_DATE);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->startOfDay() : null;
    }

    public function isOpen(DateTimeInterface $date): bool
    {
        $lock = $this->lockDate();

        return $lock === null || CarbonImmutable::instance($date)->startOfDay()->greaterThan($lock);
    }

    /**
     * Refuse a date inside the closed period.
     *
     * @throws ValidationException
     */
    public function assertOpen(DateTimeInterface $date, string $field = 'journal_date'): void
    {
        if ($this->isOpen($date)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'Period closed up to ' . $this->lockDate()?->format('d-m-Y')
                . '. Choose a date after the lock date.',
        ]);
    }

    /**
     * The rule every Finance void follows: the reversal is dated no later than
     * today, no earlier than the document it reverses, and in an open period.
     *
     * @throws ValidationException on reversal_date
     */
    public function assertReversalDate(string $reversalDate, DateTimeInterface $documentDate, string $documentLabel): CarbonImmutable
    {
        $date = CarbonImmutable::parse($reversalDate)->startOfDay();

        if ($date->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['reversal_date' => 'The reversal date cannot be in the future.']);
        }

        $original = CarbonImmutable::instance($documentDate)->startOfDay();

        if ($date->lessThan($original)) {
            throw ValidationException::withMessages([
                'reversal_date' => 'The reversal date cannot be before the ' . $documentLabel . ' date ('
                    . $original->format('d-m-Y') . ').',
            ]);
        }

        $this->assertOpen($date, 'reversal_date');

        return $date;
    }

    /** Set or clear the lock date. Audited, since reopening a period is sensitive. */
    public function setLockDate(?string $date): ?CarbonImmutable
    {
        $new = $date === null || $date === '' ? null : CarbonImmutable::parse($date)->startOfDay();

        if ($new !== null && $new->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'lock_date' => 'The lock date cannot be in the future.',
            ]);
        }

        $old = $this->lockDate();

        $this->settings->set(self::SETTING_LOCK_DATE, $new?->toDateString());

        if ($old?->toDateString() !== $new?->toDateString()) {
            $this->audit->record(
                'finance_setting',
                0,
                $new !== null && $old !== null && $new->lessThan($old) ? 'period_reopened' : 'lock_date_changed',
                ['lock_date' => $old?->toDateString()],
                ['lock_date' => $new?->toDateString()],
            );
        }

        return $new;
    }

    public function makerCheckerEnabled(): bool
    {
        return $this->settings->isEnabled(self::SETTING_MAKER_CHECKER);
    }

    public function setMakerChecker(bool $enabled): bool
    {
        $old = $this->makerCheckerEnabled();

        $this->settings->set(self::SETTING_MAKER_CHECKER, $enabled);

        if ($old !== $enabled) {
            $this->audit->record(
                'finance_setting',
                0,
                'maker_checker_changed',
                ['maker_checker' => $old],
                ['maker_checker' => $enabled],
            );
        }

        return $enabled;
    }

    /**
     * Refuse when the confirmer is the person who entered the document.
     *
     * @throws ValidationException
     */
    public function assertNotMaker(?int $createdBy, ?int $actorId): void
    {
        if (! $this->makerCheckerEnabled() || $createdBy === null || $actorId === null) {
            return;
        }

        if ($createdBy === $actorId) {
            throw ValidationException::withMessages([
                'status' => 'Maker-checker is on: the person who entered this document cannot confirm it. '
                    . 'Ask another authorised user to confirm.',
            ]);
        }
    }
}
