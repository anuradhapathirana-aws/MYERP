<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\TaxData;
use App\Models\Tax;
use App\Models\TaxCodeComponent;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single source of every tax rate (VAT, SSCL, …) used by any module.
 *
 * A rate is resolved for a DATE: the latest row of that code whose
 * effective_from is on or before it. If that row is inactive the tax is off
 * from that date and the rate is 0 — an older active row does NOT shine
 * through, otherwise switching SSCL off would silently bring back last
 * year's rate.
 */
class TaxService
{
    private const CACHE_KEY = 'core_taxes.rows';

    public function __construct(private readonly AuditLogger $audit) {}

    /** The rate of a tax code in force on a date (today when omitted); 0 when none or switched off. */
    public function rate(string $code, ?DateTimeInterface $on = null): float
    {
        $row = $this->rowInForce($code, $on);

        return $row !== null && $row['is_active'] ? (float) $row['rate'] : 0.0;
    }

    /**
     * The rate configured for a code on a date even while it is switched off —
     * the costing's pricing default. A costing adds SSCL to recover the levy in
     * the selling price; whether the invoice journal splits SSCL out is the
     * separate on/off switch read by rate().
     */
    public function configuredRate(string $code, ?DateTimeInterface $on = null): float
    {
        return (float) ($this->rowInForce($code, $on)['rate'] ?? 0.0);
    }

    /** The display name of a tax code (its latest rate period's name), or null when unknown. */
    public function name(string $code): ?string
    {
        return $this->rows()
            ->where('code', strtoupper($code))
            ->sortByDesc('effective_from')
            ->first()['name'] ?? null;
    }

    /** Whether a tax code is switched on for a date. */
    public function isActive(string $code, ?DateTimeInterface $on = null): bool
    {
        return (bool) ($this->rowInForce($code, $on)['is_active'] ?? false);
    }

    /**
     * Today's VAT and SSCL — the defaults new costings and invoices start from.
     *   sscl      — what invoices split out (0 while SSCL is switched off)
     *   sscl_rate — the configured rate, the costing's pricing default
     *
     * @return array{vat: float, sscl: float, sscl_rate: float, sscl_active: bool}
     */
    public function current(): array
    {
        return [
            'vat'         => $this->rate(Tax::VAT),
            'sscl'        => $this->rate(Tax::SSCL),
            'sscl_rate'   => $this->configuredRate(Tax::SSCL),
            'sscl_active' => $this->isActive(Tax::SSCL),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Tax::query()->orderBy('code')->orderByDesc('effective_from');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
        }

        return $query->paginate($perPage);
    }

    /** Ids of the rows in force today — the list highlights them. */
    public function currentRowIds(): array
    {
        return $this->rows()
            ->pluck('code')
            ->unique()
            ->map(fn (string $code) => $this->rowInForce($code)['id'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    public function create(TaxData $data): Tax
    {
        return DB::transaction(function () use ($data): Tax {
            $this->assertSameTypeAsCode($data, null);

            $tax = Tax::create($this->toAttributes($data) + ['created_by' => Auth::id()]);

            $this->audit->record(Tax::class, (int) $tax->id, 'created', null, $this->snapshot($tax));
            $this->flush();

            return $tax;
        });
    }

    /**
     * A built-in row keeps its code and type: VAT and SSCL are what Inventory and
     * Finance look up by name, so renaming one would quietly switch the tax off.
     */
    public function update(Tax $tax, TaxData $data): Tax
    {
        return DB::transaction(function () use ($tax, $data): Tax {
            if ($tax->is_system && ($data->code !== $tax->code || $data->tax_type !== $tax->tax_type)) {
                throw ValidationException::withMessages([
                    'code' => 'The code and type of a built-in tax cannot be changed.',
                ]);
            }

            $this->assertSameTypeAsCode($data, (int) $tax->id);

            $before = $this->snapshot($tax);
            $tax->update($this->toAttributes($data));

            [$old, $new] = $this->audit->diff($before, $this->snapshot($tax));
            if ($new !== []) {
                $this->audit->record(Tax::class, (int) $tax->id, 'updated', $old, $new);
            }

            $this->flush();

            return $tax->fresh();
        });
    }

    /**
     * Documents keep the rate they were issued with, so removing a rate period
     * never rewrites history — but a built-in row is what every lookup falls
     * back to, so it can only be switched off.
     */
    public function delete(Tax $tax): void
    {
        if ($tax->is_system) {
            throw ValidationException::withMessages([
                'id' => 'A built-in tax cannot be deleted. Switch it off (Inactive) instead.',
            ]);
        }

        // The last period of a tax that a tax code is built on would leave that
        // code charging 0 % without anyone noticing.
        $lastPeriod = ! Tax::where('code', $tax->code)->whereKeyNot($tax->id)->exists();
        if ($lastPeriod && TaxCodeComponent::where('tax_code_ref', $tax->code)->exists()) {
            throw ValidationException::withMessages([
                'id' => "Tax {$tax->code} is used by a tax code. Remove it from the code first, or switch the tax off.",
            ]);
        }

        DB::transaction(function () use ($tax): void {
            $this->audit->record(Tax::class, (int) $tax->id, 'deleted', $this->snapshot($tax));
            $tax->delete();
            $this->flush();
        });
    }

    /** @return array<string, mixed>|null */
    private function rowInForce(string $code, ?DateTimeInterface $on = null): ?array
    {
        $date = ($on ?? now())->format('Y-m-d');

        return $this->rows()
            ->where('code', strtoupper($code))
            ->filter(fn (array $row) => $row['effective_from'] <= $date)
            ->sortByDesc('effective_from')
            ->first();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(): Collection
    {
        $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => Tax::query()
            ->get(['id', 'code', 'name', 'rate', 'effective_from', 'is_active'])
            ->map(fn (Tax $t) => [
                'id'             => (int) $t->id,
                'code'           => $t->code,
                'name'           => $t->name,
                'rate'           => (float) $t->rate,
                'effective_from' => $t->effective_from->format('Y-m-d'),
                'is_active'      => (bool) $t->is_active,
            ])
            ->all());

        return collect($rows);
    }

    private function flush(): void
    {
        DB::afterCommit(fn () => Cache::forget(self::CACHE_KEY));
        Cache::forget(self::CACHE_KEY);
    }

    /** Every period of one code is the same kind of tax — VAT can't turn into SSCL from next month. */
    private function assertSameTypeAsCode(TaxData $data, ?int $ignoreId): void
    {
        $existing = Tax::where('code', $data->code)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first(['tax_type'])
            ?->tax_type;

        if ($existing !== null && $existing !== $data->tax_type) {
            throw ValidationException::withMessages([
                'tax_type' => "Code {$data->code} is already a {$existing->label()} tax. Every rate period of a code must have the same type.",
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function toAttributes(TaxData $data): array
    {
        return [
            'code'           => $data->code,
            'name'           => $data->name,
            'tax_type'       => $data->tax_type,
            'rate'           => $data->rate,
            'effective_from' => $data->effective_from,
            'is_active'      => $data->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Tax $tax): array
    {
        return [
            'code'           => $tax->code,
            'name'           => $tax->name,
            'tax_type'       => $tax->tax_type?->value,
            'rate'           => (float) $tax->rate,
            'effective_from' => $tax->effective_from?->format('Y-m-d'),
            'is_active'      => (bool) $tax->is_active,
        ];
    }
}
