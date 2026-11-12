<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TaxCodeUsage;
use App\DTOs\TaxCodeData;
use App\Enums\TaxCodeScope;
use App\Models\TaxCode;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tax-code master: what staff pick on a document line. Rates are not kept
 * here — each component names a tax in the Taxes master, whose rate on the
 * document's date applies (see TaxCalculator).
 */
class TaxCodeService
{
    public function __construct(
        private readonly TaxService  $taxes,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = TaxCode::query()->with('components')->orderBy('sort_order')->orderBy('code');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
        }

        return $query->paginate($perPage);
    }

    /**
     * Active purchase codes with each tax's rate on a date — what a GRN form
     * needs to preview its lines exactly as the server will price them.
     *
     * @return list<array<string, mixed>>
     */
    public function purchaseOptions(?DateTimeInterface $on = null): array
    {
        return TaxCode::query()
            ->forPurchases()
            ->active()
            ->with('components')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (TaxCode $code) => [
                'id'           => (int) $code->id,
                'code'         => $code->code,
                'name'         => $code->name,
                'vat_category' => $code->vat_category->value,
                'components'   => $this->describeComponents($code, $on, effective: true),
            ])
            ->all();
    }

    /**
     * Each component with its tax's name and rate on a date. $effective applies
     * the "claimable only while that tax is on for us" rule the calculator uses.
     *
     * @return list<array{tax_code_ref: string, tax_name: ?string, rate: float, is_compound: bool, is_recoverable: bool}>
     */
    public function describeComponents(TaxCode $code, ?DateTimeInterface $on = null, bool $effective = false): array
    {
        return $code->components->map(fn ($c) => [
            'tax_code_ref'   => $c->tax_code_ref,
            'tax_name'       => $this->taxes->name($c->tax_code_ref),
            'rate'           => $this->taxes->configuredRate($c->tax_code_ref, $on),
            'is_compound'    => (bool) $c->is_compound,
            'is_recoverable' => (bool) $c->is_recoverable && (! $effective || $this->taxes->isActive($c->tax_code_ref, $on)),
        ])->values()->all();
    }

    public function create(TaxCodeData $data): TaxCode
    {
        return DB::transaction(function () use ($data): TaxCode {
            $code = TaxCode::create($this->toAttributes($data) + [
                'scope'      => TaxCodeScope::Purchase,
                'is_system'  => false,
                'created_by' => Auth::id(),
            ]);

            $this->syncComponents($code, $data->components);

            $this->audit->record(TaxCode::class, (int) $code->id, 'created', null, $this->snapshot($code));

            return $code->load('components');
        });
    }

    /**
     * A built-in code keeps its code, category and taxes: documents and supplier
     * defaults rely on what "VAT-IN" means. Its name, order and on/off can change.
     */
    public function update(TaxCode $code, TaxCodeData $data): TaxCode
    {
        return DB::transaction(function () use ($code, $data): TaxCode {
            $before = $this->snapshot($code);

            if ($code->is_system && (
                $data->code !== $code->code
                || $data->vat_category !== $code->vat_category
                || $data->components !== $before['components']
            )) {
                throw ValidationException::withMessages([
                    'code' => 'The code, VAT category and taxes of a built-in tax code cannot be changed. Create a new code instead.',
                ]);
            }

            $code->update($this->toAttributes($data));

            if (! $code->is_system) {
                $this->syncComponents($code, $data->components);
            }

            [$old, $new] = $this->audit->diff($before, $this->snapshot($code->fresh('components')));
            if ($new !== []) {
                $this->audit->record(TaxCode::class, (int) $code->id, 'updated', $old, $new);
            }

            return $code->fresh('components');
        });
    }

    /** Never while a document or supplier still points at it — switch it off instead. */
    public function delete(TaxCode $code): void
    {
        if ($code->is_system) {
            throw ValidationException::withMessages([
                'id' => 'A built-in tax code cannot be deleted. Switch it off (Inactive) instead.',
            ]);
        }

        foreach (app()->tagged(TaxCodeUsage::TAG) as $usage) {
            if (($where = $usage->usedBy((int) $code->id)) !== null) {
                throw ValidationException::withMessages([
                    'id' => "Tax code {$code->code} is used as {$where}. Switch it off (Inactive) instead.",
                ]);
            }
        }

        DB::transaction(function () use ($code): void {
            $this->audit->record(TaxCode::class, (int) $code->id, 'deleted', $this->snapshot($code));
            $code->delete();
        });
    }

    /** @param list<array{tax_code_ref: string, is_compound: bool, is_recoverable: bool}> $components */
    private function syncComponents(TaxCode $code, array $components): void
    {
        $code->components()->delete();

        foreach ($components as $i => $c) {
            $code->components()->create($c + ['sequence' => $i + 1]);
        }
    }

    /** @return array<string, mixed> */
    private function toAttributes(TaxCodeData $data): array
    {
        return [
            'code'         => $data->code,
            'name'         => $data->name,
            'vat_category' => $data->vat_category,
            'is_active'    => $data->is_active,
            'sort_order'   => $data->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(TaxCode $code): array
    {
        $code->loadMissing('components');

        return [
            'code'         => $code->code,
            'name'         => $code->name,
            'vat_category' => $code->vat_category?->value,
            'is_active'    => (bool) $code->is_active,
            'sort_order'   => (int) $code->sort_order,
            'components'   => $code->components->map(fn ($c) => [
                'tax_code_ref'   => $c->tax_code_ref,
                'is_compound'    => (bool) $c->is_compound,
                'is_recoverable' => (bool) $c->is_recoverable,
            ])->values()->all(),
        ];
    }
}
