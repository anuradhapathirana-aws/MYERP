<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\Company $this */
        return [
            'id'              => $this->id,
            'company_type'    => $this->company_type,
            'company_name'    => $this->company_name,
            'registration_no' => $this->registration_no,
            'tax_reg_no'      => $this->tax_reg_no,
            'street_address'  => $this->street_address,
            'city'            => $this->city,
            'country'         => $this->country,
            'state'           => $this->state,
            'postal_zip_code' => $this->postal_zip_code,
            'company_email'   => $this->company_email,
            'company_email_2' => $this->company_email_2,
            'company_mobile'  => $this->company_mobile,
            'industry_id'     => $this->industry_id,
            'industry'        => $this->whenLoaded('industry', fn () => [
                'id'   => $this->industry->id,
                'name' => $this->industry->name,
            ]),
            'created_at'      => $this->created_at->toISOString(),
            'updated_at'      => $this->updated_at->toISOString(),
        ];
    }
}
