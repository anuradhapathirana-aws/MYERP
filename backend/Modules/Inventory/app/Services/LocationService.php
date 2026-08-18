<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\LocationData;
use Modules\Inventory\Models\Location;

class LocationService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Location::with(['company', 'industry', 'parentLocation'])
            ->orderBy('location_name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('location_name', 'like', $term)
                  ->orWhere('location_code', 'like', $term);
            });
        }

        if (!empty($filters['type'])) {
            $query->where('location_type', $filters['type']);
        }

        if (!empty($filters['city'])) {
            $query->where('loc_city', 'like', '%' . $filters['city'] . '%');
        }

        if (!empty($filters['country'])) {
            $query->where('country', 'like', '%' . $filters['country'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        return Location::select('id', 'location_code', 'location_name', 'parent_location_id')
            ->orderBy('location_name')
            ->get();
    }

    public function find(int $id): Location
    {
        return Location::with(['company', 'industry', 'parentLocation'])->findOrFail($id);
    }

    public function create(LocationData $data): Location
    {
        $location = Location::create($this->toAttributes($data));

        return $location->load(['company', 'industry', 'parentLocation']);
    }

    public function update(Location $location, LocationData $data): Location
    {
        $location->update($this->toAttributes($data));

        return $location->fresh(['company', 'industry', 'parentLocation']);
    }

    public function delete(Location $location): void
    {
        $location->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(LocationData $data): array
    {
        return [
            'company_id'               => $data->company_id,
            'industry_id'              => $data->industry_id,
            'parent_location_id'       => $data->parent_location_id,
            'location_code'            => $data->location_code,
            'location_name'            => $data->location_name,
            'location_type'            => $data->location_type,
            'country'                  => $data->country,
            'loc_street_address'       => $data->loc_street_address,
            'loc_city'                 => $data->loc_city,
            'loc_country'              => $data->loc_country,
            'loc_state'                => $data->loc_state,
            'loc_postal_zip_code'      => $data->loc_postal_zip_code,
            'billing_same_as_location' => $data->billing_same_as_location,
            'bill_street_address'      => $data->bill_street_address,
            'bill_city'                => $data->bill_city,
            'bill_country'             => $data->bill_country,
            'bill_state'               => $data->bill_state,
            'bill_postal_zip_code'     => $data->bill_postal_zip_code,
            'company_email'            => $data->company_email,
            'customer_facing_email'    => $data->customer_facing_email,
            'company_phone'            => $data->company_phone,
            'mobile'                   => $data->mobile,
            'fax'                      => $data->fax,
            'website'                  => $data->website,
            'longitude'                => $data->longitude,
            'latitude'                 => $data->latitude,
            'map_url'                  => $data->map_url,
            'date_format'              => $data->date_format,
            'number_format'            => $data->number_format,
            'time_format'              => $data->time_format,
            'float_precision'          => $data->float_precision,
            'base_currency'            => $data->base_currency,
            'time_zone'                => $data->time_zone,
            'financial_year'           => $data->financial_year,
            'open_hours_from'          => $data->open_hours_from,
            'open_hours_to'            => $data->open_hours_to,
            'available_modules'        => $data->available_modules,
            'stock_releasing_method'   => $data->stock_releasing_method,
            'logo_path'                => $data->logo_path,
            'header_path'              => $data->header_path,
            'footer_path'              => $data->footer_path,
        ];
    }
}
