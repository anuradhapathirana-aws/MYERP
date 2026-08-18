<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreStoreRequest;
use Modules\Inventory\Http\Requests\UpdateStoreRequest;

final class StoreData
{
    public function __construct(
        public readonly int     $store_type_id,
        public readonly string  $store_code,
        public readonly string  $store_name,
        public readonly ?string $uom,
        public readonly ?float  $capacity,
        public readonly ?int    $location_id,
        public readonly ?int    $parent_store_id,
        public readonly ?string $address_line_1,
        public readonly ?string $address_line_2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $postal_code,
        public readonly ?string $manager_name,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $description,
        public readonly bool    $is_active,
    ) {}

    public static function fromStoreRequest(StoreStoreRequest $request): self
    {
        return self::fromInput($request->all());
    }

    public static function fromUpdateRequest(UpdateStoreRequest $request): self
    {
        return self::fromInput($request->all());
    }

    /** @param array<string, mixed> $input */
    private static function fromInput(array $input): self
    {
        return new self(
            store_type_id:  (int) $input['store_type_id'],
            store_code:     trim($input['store_code']),
            store_name:     trim($input['store_name']),
            uom:            isset($input['uom']) && $input['uom'] !== '' ? trim($input['uom']) : null,
            capacity:       isset($input['capacity']) && $input['capacity'] !== '' ? (float) $input['capacity'] : null,
            location_id:      isset($input['location_id'])     && $input['location_id']     !== '' ? (int) $input['location_id']     : null,
            parent_store_id:  isset($input['parent_store_id']) && $input['parent_store_id'] !== '' ? (int) $input['parent_store_id'] : null,
            address_line_1: isset($input['address_line_1']) && $input['address_line_1'] !== '' ? trim($input['address_line_1']) : null,
            address_line_2: isset($input['address_line_2']) && $input['address_line_2'] !== '' ? trim($input['address_line_2']) : null,
            city:           isset($input['city'])           && $input['city']           !== '' ? trim($input['city'])           : null,
            state:          isset($input['state'])          && $input['state']          !== '' ? trim($input['state'])          : null,
            country:        isset($input['country'])        && $input['country']        !== '' ? trim($input['country'])        : null,
            postal_code:    isset($input['postal_code'])    && $input['postal_code']    !== '' ? trim($input['postal_code'])    : null,
            manager_name:   isset($input['manager_name'])   && $input['manager_name']   !== '' ? trim($input['manager_name'])   : null,
            phone:          isset($input['phone'])          && $input['phone']          !== '' ? trim($input['phone'])          : null,
            email:          isset($input['email'])          && $input['email']          !== '' ? trim($input['email'])          : null,
            description:    isset($input['description'])    && $input['description']    !== '' ? trim($input['description'])    : null,
            is_active:      (bool) ($input['is_active'] ?? true),
        );
    }
}
