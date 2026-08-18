<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'supplier_code'              => $this->supplier_code,
            'reference_no'               => $this->reference_no,
            'supplier_type'              => $this->supplier_type,
            'supplier_name'              => $this->supplier_name,
            'check_writer_name'          => $this->check_writer_name,

            'mobile'                     => $this->mobile,
            'land_line'                  => $this->land_line,
            'email'                      => $this->email,
            'wechat'                     => $this->wechat,
            'whatsapp'                   => $this->whatsapp,
            'fax'                        => $this->fax,
            'website'                    => $this->website,

            'bil_address_line_1'         => $this->bil_address_line_1,
            'bil_address_line_2'         => $this->bil_address_line_2,
            'bil_address_line_3'         => $this->bil_address_line_3,
            'bil_city'                   => $this->bil_city,
            'bil_postal_code'            => $this->bil_postal_code,
            'bil_country'                => $this->bil_country,
            'bil_state_province'         => $this->bil_state_province,

            'tax_type'                   => $this->tax_type,
            'tax_no'                     => $this->tax_no,
            'tax_regis_no'               => $this->tax_regis_no,

            'credit_limit'               => $this->credit_limit,
            'credit_period'              => $this->credit_period,
            'privileges_discount'        => $this->privileges_discount,

            'bank_name'                  => $this->bank_name,
            'bank_branch'                => $this->bank_branch,
            'bank_acc_holder_name'       => $this->bank_acc_holder_name,
            'bank_acc_no'                => $this->bank_acc_no,

            'contact_person_name'        => $this->contact_person_name,
            'contact_person_designation' => $this->contact_person_designation,
            'contact_person_mobile'      => $this->contact_person_mobile,
            'contact_person_email'       => $this->contact_person_email,
            'contact_person_fax'         => $this->contact_person_fax,

            'products_count'             => $this->whenCounted('products'),
            'products'                   => $this->whenLoaded('products', fn () =>
                $this->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ),

            'created_at'                 => $this->created_at?->toISOString(),
            'updated_at'                 => $this->updated_at?->toISOString(),
        ];
    }
}
