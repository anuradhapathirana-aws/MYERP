<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\ChargeTypeData;
use Modules\Finance\Enums\ChargeTreatment;
use Modules\Finance\Models\BillLine;
use Modules\Finance\Models\ChargeType;

/**
 * The Charge Types master: what a bill line can be for, and where its money
 * goes. A posted bill keeps the treatment it was saved with (fin_bill_lines.
 * treatment), so editing a type here only changes bills entered from now on.
 */
class ChargeTypeService
{
    public const AUDIT_TYPE = 'charge_type';

    /** Import VAT is a tax, not a cost choice: its treatment is fixed. */
    private const LOCKED_TREATMENT = ['IMPORT_VAT'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = ChargeType::query()
            ->with('ledgerAccount:id,code,ledger_account_name')
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
        }

        return $query->paginate($perPage);
    }

    /** @return Collection<int, ChargeType> What a bill line can pick. */
    public function active(): Collection
    {
        return ChargeType::query()->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function create(ChargeTypeData $data): ChargeType
    {
        return DB::transaction(function () use ($data): ChargeType {
            $type = new ChargeType($this->toAttributes($data));
            $type->is_system  = false;
            $type->created_by = Auth::id();
            $type->save();

            $this->audit->record(self::AUDIT_TYPE, (int) $type->id, 'created', null, $this->snapshot($type));

            return $type->load('ledgerAccount:id,code,ledger_account_name');
        });
    }

    /**
     * A built-in type keeps its code (bills and reports know it by it), and
     * Import VAT keeps its treatment. Everything else -- name, account, costing
     * rows, and the treatment of the other built-in types -- is the client's
     * accounting choice.
     */
    public function update(ChargeType $type, ChargeTypeData $data): ChargeType
    {
        return DB::transaction(function () use ($type, $data): ChargeType {
            /** @var ChargeType $locked */
            $locked = ChargeType::whereKey($type->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            if ($locked->is_system && $data->code !== $locked->code) {
                throw ValidationException::withMessages([
                    'code' => 'The code of a built-in charge type cannot be changed.',
                ]);
            }

            if (in_array($locked->code, self::LOCKED_TREATMENT, true) && $data->treatment !== $locked->treatment) {
                throw ValidationException::withMessages([
                    'treatment' => $locked->name . ' is a tax: it is always claimable, and becomes cost by itself while VAT is off.',
                ]);
            }

            $locked->update($this->toAttributes($data));

            [$old, $new] = $this->audit->diff($before, $this->snapshot($locked));
            if ($new !== []) {
                $this->audit->record(self::AUDIT_TYPE, (int) $locked->id, 'updated', $old, $new);
            }

            return $locked->load('ledgerAccount:id,code,ledger_account_name');
        });
    }

    /** Never a built-in type, and never one a bill already uses -- switch it off instead. */
    public function delete(ChargeType $type): void
    {
        if ($type->is_system) {
            throw ValidationException::withMessages([
                'id' => 'A built-in charge type cannot be deleted. Switch it off (Inactive) instead.',
            ]);
        }

        DB::transaction(function () use ($type): void {
            /** @var ChargeType $locked */
            $locked = ChargeType::whereKey($type->id)->lockForUpdate()->firstOrFail();

            $used = BillLine::where('charge_type_id', $locked->id)->count();

            if ($used > 0) {
                throw ValidationException::withMessages([
                    'id' => "{$locked->name} is used on {$used} bill " . ($used === 1 ? 'line' : 'lines') . '. Switch it off (Inactive) instead.',
                ]);
            }

            $this->audit->record(self::AUDIT_TYPE, (int) $locked->id, 'deleted', $this->snapshot($locked));
            $locked->delete();
        });
    }

    /** @return array<string, mixed> */
    private function toAttributes(ChargeTypeData $data): array
    {
        return [
            'code'                => $data->code,
            'name'                => $data->name,
            'treatment'           => $data->treatment,
            'ledger_account_id'   => $data->ledger_account_id,
            'fob_expense_type_id' => $data->fob_expense_type_id,
            'cif_expense_type_id' => $data->cif_expense_type_id,
            'is_active'           => $data->is_active,
            'sort_order'          => $data->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(ChargeType $type): array
    {
        return [
            'code'                => $type->code,
            'name'                => $type->name,
            'treatment'           => $type->treatment instanceof ChargeTreatment ? $type->treatment->value : $type->treatment,
            'ledger_account_id'   => $type->ledger_account_id,
            'fob_expense_type_id' => $type->fob_expense_type_id,
            'cif_expense_type_id' => $type->cif_expense_type_id,
            'is_active'           => (bool) $type->is_active,
            'sort_order'          => (int) $type->sort_order,
        ];
    }
}
