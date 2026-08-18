<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\UserRequest;

final class UserData
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $email,
        public readonly array   $roles,
        public readonly array   $activeModules,
        public readonly ?string $password,
    ) {}

    public static function fromRequest(UserRequest $request): self
    {
        return new self(
            name:          $request->validated('name'),
            email:         $request->validated('email'),
            roles:         $request->validated('roles'),
            activeModules: $request->validated('active_modules', []),
            password:      $request->validated('password'),
        );
    }
}
