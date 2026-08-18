<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\SupplierMasterData;
use Modules\Inventory\Models\SupplierMaster;

class SupplierMasterService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierMaster::withCount('products')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('supplier_name', 'like', $term)
                  ->orWhere('supplier_code', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if (!empty($filters['supplier_type'])) {
            $query->where('supplier_type', $filters['supplier_type']);
        }

        if (!empty($filters['mobile'])) {
            $query->where('mobile', 'like', '%' . $filters['mobile'] . '%');
        }

        if (!empty($filters['bil_city'])) {
            $query->where('bil_city', 'like', '%' . $filters['bil_city'] . '%');
        }

        if (!empty($filters['bil_country'])) {
            $query->where('bil_country', 'like', '%' . $filters['bil_country'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): SupplierMaster
    {
        return SupplierMaster::withCount('products')->findOrFail($id);
    }

    public function create(SupplierMasterData $data): SupplierMaster
    {
        return DB::transaction(function () use ($data): SupplierMaster {
            $attributes = $this->toAttributes($data);
            $attributes['supplier_code'] = $this->generateSupplierCode();

            return SupplierMaster::create($attributes);
        });
    }

    public function update(SupplierMaster $supplier, SupplierMasterData $data): SupplierMaster
    {
        // supplier_code is immutable once assigned — never overwritten on update.
        $supplier->update($this->toAttributes($data));

        return $supplier->loadCount('products');
    }

    /** Preview the next supplier code (non-locking, for display only) */
    public function nextSupplierCode(): string
    {
        $prefix = 'SUP-';

        $last = SupplierMaster::where('supplier_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('supplier_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next supplier code (must be called inside a DB transaction) */
    private function generateSupplierCode(): string
    {
        $prefix = 'SUP-';

        $last = SupplierMaster::where('supplier_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('supplier_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function delete(SupplierMaster $supplier): void
    {
        $supplier->delete();
    }

    /** List for dropdowns — includes contact & address fields for PO auto-fill. */
    public function all(): Collection
    {
        return SupplierMaster::orderBy('supplier_name')
            ->get([
                'id',
                'supplier_name',
                'contact_person_name',
                'contact_person_mobile',
                'bil_address_line_1',
                'bil_address_line_2',
                'bil_address_line_3',
                'bil_city',
                'bil_postal_code',
                'bil_state_province',
                'bil_country',
            ]);
    }

    private function toAttributes(SupplierMasterData $data): array
    {
        return [
            'supplier_name'              => $data->supplierName,
            'reference_no'               => $data->referenceNo,
            'supplier_type'              => $data->supplierType,
            'check_writer_name'          => $data->checkWriterName,
            'mobile'                     => $data->mobile,
            'land_line'                  => $data->landLine,
            'email'                      => $data->email,
            'wechat'                     => $data->wechat,
            'whatsapp'                   => $data->whatsapp,
            'fax'                        => $data->fax,
            'website'                    => $data->website,
            'bil_address_line_1'         => $data->bilAddressLine1,
            'bil_address_line_2'         => $data->bilAddressLine2,
            'bil_address_line_3'         => $data->bilAddressLine3,
            'bil_city'                   => $data->bilCity,
            'bil_postal_code'            => $data->bilPostalCode,
            'bil_country'                => $data->bilCountry,
            'bil_state_province'         => $data->bilStateProvince,
            'tax_type'                   => $data->taxType,
            'tax_no'                     => $data->taxNo,
            'tax_regis_no'               => $data->taxRegisNo,
            'credit_limit'               => $data->creditLimit,
            'credit_period'              => $data->creditPeriod,
            'privileges_discount'        => $data->privilegesDiscount,
            'bank_name'                  => $data->bankName,
            'bank_branch'                => $data->bankBranch,
            'bank_acc_holder_name'       => $data->bankAccHolderName,
            'bank_acc_no'                => $data->bankAccNo,
            'contact_person_name'        => $data->contactPersonName,
            'contact_person_designation' => $data->contactPersonDesignation,
            'contact_person_mobile'      => $data->contactPersonMobile,
            'contact_person_email'       => $data->contactPersonEmail,
            'contact_person_fax'         => $data->contactPersonFax,
        ];
    }
}
