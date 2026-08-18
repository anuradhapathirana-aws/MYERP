<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CompanyData;
use Modules\Inventory\Models\Company;

class CompanyService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return Company::with('industry')
            ->orderBy('company_name')
            ->paginate($perPage);
    }

    public function find(int $id): Company
    {
        return Company::with('industry')->findOrFail($id);
    }

    public function create(CompanyData $data): Company
    {
        $company = Company::create($this->toAttributes($data));

        return $company->load('industry');
    }

    public function update(Company $company, CompanyData $data): Company
    {
        $company->update($this->toAttributes($data));

        return $company->fresh('industry');
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(CompanyData $data): array
    {
        return [
            'company_type'    => $data->company_type,
            'company_name'    => $data->company_name,
            'registration_no' => $data->registration_no,
            'tax_reg_no'      => $data->tax_reg_no,
            'street_address'  => $data->street_address,
            'city'            => $data->city,
            'country'         => $data->country,
            'state'           => $data->state,
            'postal_zip_code' => $data->postal_zip_code,
            'company_email'   => $data->company_email,
            'company_email_2' => $data->company_email_2,
            'company_mobile'  => $data->company_mobile,
            'industry_id'     => $data->industry_id,
        ];
    }
}
