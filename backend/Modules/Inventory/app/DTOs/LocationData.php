<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\LocationRequest;

final class LocationData
{
    public function __construct(
        public readonly int     $company_id,
        public readonly int     $industry_id,
        public readonly ?int    $parent_location_id,
        public readonly string  $location_code,
        public readonly string  $location_name,
        public readonly ?string $location_type,
        public readonly string  $country,
        // Location Address
        public readonly string  $loc_street_address,
        public readonly string  $loc_city,
        public readonly string  $loc_country,
        public readonly string  $loc_state,
        public readonly string  $loc_postal_zip_code,
        // Billing Address
        public readonly bool    $billing_same_as_location,
        public readonly ?string $bill_street_address,
        public readonly ?string $bill_city,
        public readonly ?string $bill_country,
        public readonly ?string $bill_state,
        public readonly ?string $bill_postal_zip_code,
        // Contact
        public readonly ?string $company_email,
        public readonly ?string $customer_facing_email,
        public readonly ?string $company_phone,
        public readonly ?string $mobile,
        public readonly ?string $fax,
        public readonly ?string $website,
        public readonly ?float  $longitude,
        public readonly ?float  $latitude,
        public readonly ?string $map_url,
        // Advanced Settings
        public readonly ?string $date_format,
        public readonly ?string $number_format,
        public readonly ?string $time_format,
        public readonly ?int    $float_precision,
        public readonly string  $base_currency,
        public readonly ?string $time_zone,
        public readonly string  $financial_year,
        public readonly ?string $open_hours_from,
        public readonly ?string $open_hours_to,
        // Module & Inventory
        public readonly ?array  $available_modules,
        public readonly string  $stock_releasing_method,
        // Media
        public readonly ?string $logo_path,
        public readonly ?string $header_path,
        public readonly ?string $footer_path,
    ) {}

    public static function fromRequest(LocationRequest $request): self
    {
        $same = (bool) $request->input('billing_same_as_location', false);

        return new self(
            company_id:                (int) $request->input('company_id'),
            industry_id:               (int) $request->input('industry_id'),
            parent_location_id:        $request->filled('parent_location_id') ? (int) $request->input('parent_location_id') : null,
            location_code:             trim($request->input('location_code')),
            location_name:             trim($request->input('location_name')),
            location_type:             $request->filled('location_type')  ? trim($request->input('location_type'))  : null,
            country:                   trim($request->input('country')),
            loc_street_address:        trim($request->input('loc_street_address')),
            loc_city:                  trim($request->input('loc_city')),
            loc_country:               trim($request->input('loc_country')),
            loc_state:                 trim($request->input('loc_state')),
            loc_postal_zip_code:       trim($request->input('loc_postal_zip_code')),
            billing_same_as_location:  $same,
            bill_street_address:       $request->filled('bill_street_address') ? trim($request->input('bill_street_address')) : null,
            bill_city:                 $request->filled('bill_city')    ? trim($request->input('bill_city'))    : null,
            bill_country:              $request->filled('bill_country') ? trim($request->input('bill_country')) : null,
            bill_state:                $request->filled('bill_state')   ? trim($request->input('bill_state'))   : null,
            bill_postal_zip_code:      $request->filled('bill_postal_zip_code') ? trim($request->input('bill_postal_zip_code')) : null,
            company_email:             $request->filled('company_email')             ? trim($request->input('company_email'))             : null,
            customer_facing_email:     $request->filled('customer_facing_email')     ? trim($request->input('customer_facing_email'))     : null,
            company_phone:             $request->filled('company_phone')             ? trim($request->input('company_phone'))             : null,
            mobile:                    $request->filled('mobile')                    ? trim($request->input('mobile'))                    : null,
            fax:                       $request->filled('fax')                       ? trim($request->input('fax'))                       : null,
            website:                   $request->filled('website')                   ? trim($request->input('website'))                   : null,
            longitude:                 $request->filled('longitude')  ? (float) $request->input('longitude')  : null,
            latitude:                  $request->filled('latitude')   ? (float) $request->input('latitude')   : null,
            map_url:                   $request->filled('map_url')    ? trim($request->input('map_url'))       : null,
            date_format:               $request->filled('date_format')    ? trim($request->input('date_format'))    : null,
            number_format:             $request->filled('number_format')  ? trim($request->input('number_format'))  : null,
            time_format:               $request->filled('time_format')    ? trim($request->input('time_format'))    : null,
            float_precision:           $request->filled('float_precision') ? (int) $request->input('float_precision') : null,
            base_currency:             trim($request->input('base_currency', 'USD')),
            time_zone:                 $request->filled('time_zone') ? trim($request->input('time_zone')) : null,
            financial_year:            trim($request->input('financial_year')),
            open_hours_from:           $request->filled('open_hours_from') ? $request->input('open_hours_from') : null,
            open_hours_to:             $request->filled('open_hours_to')   ? $request->input('open_hours_to')   : null,
            available_modules:         $request->input('available_modules') ?? null,
            stock_releasing_method:    trim($request->input('stock_releasing_method')),
            logo_path:                 null,
            header_path:               null,
            footer_path:               null,
        );
    }
}
