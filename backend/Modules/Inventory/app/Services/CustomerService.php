<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\CustomerData;
use Modules\Inventory\Models\CustomerMaster;

class CustomerService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = CustomerMaster::orderBy('customer_name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', $term)
                  ->orWhere('customer_code', 'like', $term)
                  ->orWhere('customer_email', 'like', $term);
            });
        }

        if (!empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

        if (!empty($filters['billing_city'])) {
            $query->where('billing_city', 'like', '%' . $filters['billing_city'] . '%');
        }

        if (!empty($filters['billing_country'])) {
            $query->where('billing_country', 'like', '%' . $filters['billing_country'] . '%');
        }

        if (!empty($filters['customer_code'])) {
            $query->where('customer_code', 'like', '%' . $filters['customer_code'] . '%');
        }

        if (!empty($filters['mobile'])) {
            $query->where('customer_mobile', 'like', '%' . $filters['mobile'] . '%');
        }

        if (!empty($filters['email'])) {
            $query->where('customer_email', 'like', '%' . $filters['email'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): CustomerMaster
    {
        return CustomerMaster::findOrFail($id);
    }

    public function create(CustomerData $data): CustomerMaster
    {
        return DB::transaction(function () use ($data): CustomerMaster {
            $attributes = $this->toAttributes($data);
            $attributes['customer_code'] = $this->generateCustomerCode();

            return CustomerMaster::create($attributes);
        });
    }

    public function update(CustomerMaster $customer, CustomerData $data): CustomerMaster
    {
        // customer_code is immutable once assigned — never overwritten on update.
        $customer->update($this->toAttributes($data));

        return $customer->fresh();
    }

    /** Preview the next auto-generated customer code (non-locking, for display only) */
    public function nextCustomerCode(): string
    {
        $prefix = 'CUS-';

        $last = CustomerMaster::where('customer_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('customer_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next customer code (must be called inside a DB transaction) */
    private function generateCustomerCode(): string
    {
        $prefix = 'CUS-';

        $last = CustomerMaster::where('customer_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('customer_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function delete(CustomerMaster $customer): void
    {
        $customer->delete();
    }

    /** Lightweight list for dropdowns — lookup fields only. */
    public function all(): Collection
    {
        return CustomerMaster::orderBy('customer_name')
            ->get([
                'id',
                'customer_name',
                'customer_code',
                'customer_type',
                'shipping_address_line1',
                'shipping_address_line2',
                'shipping_address_line3',
                'shipping_city',
                'shipping_state_province',
                'shipping_zip_postal',
                'shipping_country',
            ]);
    }

    private function toAttributes(CustomerData $data): array
    {
        return [
            'reference_no'                 => $data->referenceNo,
            'title'                        => $data->title,
            'customer_type'                => $data->customerType,
            'customer_name'                => $data->customerName,
            'nic_passport_driving_licence' => $data->nicPassportDrivingLicence,
            'attachments'                  => $data->attachments,
            'br_no'                        => $data->brNo,
            'customer_tin'                 => $data->customerTin,
            'customer_mobile'              => $data->customerMobile,
            'customer_land_line'           => $data->customerLandLine,
            'customer_email'               => $data->customerEmail,
            'customer_fax'                 => $data->customerFax,
            'billing_address_line1'        => $data->billingAddressLine1,
            'billing_address_line2'        => $data->billingAddressLine2,
            'billing_address_line3'        => $data->billingAddressLine3,
            'billing_city'                 => $data->billingCity,
            'billing_zip_postal'           => $data->billingZipPostal,
            'billing_state_province'       => $data->billingStateProvince,
            'billing_country'              => $data->billingCountry,
            'shipping_address_line1'       => $data->shippingAddressLine1,
            'shipping_address_line2'       => $data->shippingAddressLine2,
            'shipping_address_line3'       => $data->shippingAddressLine3,
            'shipping_city'                => $data->shippingCity,
            'shipping_zip_postal'          => $data->shippingZipPostal,
            'shipping_state_province'      => $data->shippingStateProvince,
            'shipping_country'             => $data->shippingCountry,
            'sale_manager'                 => $data->saleManager,
            'sales_executive'              => $data->salesExecutive,
            'sales_person'                 => $data->salesPerson,
        ];
    }
}
