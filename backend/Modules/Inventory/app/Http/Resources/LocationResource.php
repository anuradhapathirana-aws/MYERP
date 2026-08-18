<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\Location $this */
        return [
            'id'                       => $this->id,
            'company_id'               => $this->company_id,
            'industry_id'              => $this->industry_id,
            'parent_location_id'       => $this->parent_location_id,
            'location_code'            => $this->location_code,
            'location_name'            => $this->location_name,
            'location_type'            => $this->location_type,
            'country'                  => $this->country,
            // Location Address
            'loc_street_address'       => $this->loc_street_address,
            'loc_city'                 => $this->loc_city,
            'loc_country'              => $this->loc_country,
            'loc_state'                => $this->loc_state,
            'loc_postal_zip_code'      => $this->loc_postal_zip_code,
            // Billing Address
            'billing_same_as_location' => $this->billing_same_as_location,
            'bill_street_address'      => $this->bill_street_address,
            'bill_city'                => $this->bill_city,
            'bill_country'             => $this->bill_country,
            'bill_state'               => $this->bill_state,
            'bill_postal_zip_code'     => $this->bill_postal_zip_code,
            // Contact
            'company_email'            => $this->company_email,
            'customer_facing_email'    => $this->customer_facing_email,
            'company_phone'            => $this->company_phone,
            'mobile'                   => $this->mobile,
            'fax'                      => $this->fax,
            'website'                  => $this->website,
            'longitude'                => $this->longitude,
            'latitude'                 => $this->latitude,
            'map_url'                  => $this->map_url,
            // Advanced Settings
            'date_format'              => $this->date_format,
            'number_format'            => $this->number_format,
            'time_format'              => $this->time_format,
            'float_precision'          => $this->float_precision,
            'base_currency'            => $this->base_currency,
            'time_zone'                => $this->time_zone,
            'financial_year'           => $this->financial_year,
            'open_hours_from'          => $this->open_hours_from,
            'open_hours_to'            => $this->open_hours_to,
            // Module & Inventory
            'available_modules'        => $this->available_modules ?? [],
            'stock_releasing_method'   => $this->stock_releasing_method,
            // Media
            'logo_path'                => $this->logo_path,
            'header_path'              => $this->header_path,
            'footer_path'              => $this->footer_path,
            // Relations
            'company'         => $this->whenLoaded('company', fn () => [
                'id'   => $this->company->id,
                'name' => $this->company->company_name,
            ]),
            'industry'        => $this->whenLoaded('industry', fn () => [
                'id'   => $this->industry->id,
                'name' => $this->industry->name,
            ]),
            'parent_location' => $this->whenLoaded('parentLocation', fn () => $this->parentLocation ? [
                'id'   => $this->parentLocation->id,
                'name' => $this->parentLocation->location_name,
                'code' => $this->parentLocation->location_code,
            ] : null),
            'created_at'      => $this->created_at->toISOString(),
            'updated_at'      => $this->updated_at->toISOString(),
        ];
    }
}
