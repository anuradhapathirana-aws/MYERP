<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->id,
            'customer_code'                => $this->customer_code,
            'reference_no'                 => $this->reference_no,
            'title'                        => $this->title,
            'customer_type'                => $this->customer_type,
            'customer_name'                => $this->customer_name,
            'nic_passport_driving_licence' => $this->nic_passport_driving_licence,
            'br_no'                        => $this->br_no,
            'customer_tin'                 => $this->customer_tin,

            'customer_mobile'              => $this->customer_mobile,
            'customer_land_line'           => $this->customer_land_line,
            'customer_email'               => $this->customer_email,
            'customer_fax'                 => $this->customer_fax,

            'billing_address_line1'        => $this->billing_address_line1,
            'billing_address_line2'        => $this->billing_address_line2,
            'billing_address_line3'        => $this->billing_address_line3,
            'billing_city'                 => $this->billing_city,
            'billing_zip_postal'           => $this->billing_zip_postal,
            'billing_state_province'       => $this->billing_state_province,
            'billing_country'              => $this->billing_country,

            'shipping_address_line1'       => $this->shipping_address_line1,
            'shipping_address_line2'       => $this->shipping_address_line2,
            'shipping_address_line3'       => $this->shipping_address_line3,
            'shipping_city'                => $this->shipping_city,
            'shipping_zip_postal'          => $this->shipping_zip_postal,
            'shipping_state_province'      => $this->shipping_state_province,
            'shipping_country'             => $this->shipping_country,

            'sale_manager'                 => $this->sale_manager,
            'sales_executive'              => $this->sales_executive,
            'sales_person'                 => $this->sales_person,

            'attachments'                  => $this->whenLoaded(
                'attachmentFiles',
                fn () => $this->attachmentFiles->map(fn ($a) => [
                    'id'        => $a->id,
                    'file_name' => $a->file_name,
                    'file_path' => $a->file_path,
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                    'url'       => '/storage/' . $a->file_path,
                ])->values()->all(),
                []
            ),

            'created_at'                   => $this->created_at?->toISOString(),
            'updated_at'                   => $this->updated_at?->toISOString(),
        ];
    }
}
