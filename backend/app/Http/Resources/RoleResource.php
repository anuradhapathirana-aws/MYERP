<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'label'             => ucwords(str_replace('_', ' ', $this->name)),
            'is_system'         => in_array($this->name, ['super_admin', 'admin', 'staff'], true),
            'permissions'       => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')->values()),
            'permissions_count' => $this->whenCounted('permissions'),
        ];
    }
}
