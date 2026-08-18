<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(): JsonResponse
    {
        $roles = $this->service->all();

        return response()->json(['data' => RoleResource::collection($roles)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
        ]);

        $name = strtolower(str_replace(' ', '_', trim($request->string('name')->toString())));
        $role = $this->service->create($name);

        return response()->json(['data' => new RoleResource($role)], 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role = $this->service->find($role);

        return response()->json(['data' => new RoleResource($role)]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($this->service->isSystem($role)) {
            return response()->json(['message' => 'System roles cannot be renamed.'], 422);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:50', "unique:roles,name,{$role->id}"],
        ]);

        $name = strtolower(str_replace(' ', '_', trim($request->string('name')->toString())));
        $role = $this->service->update($role, $name);

        return response()->json(['data' => new RoleResource($role)]);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($this->service->isSystem($role)) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 422);
        }

        $this->service->delete($role);

        return response()->json(null, 204);
    }

    /** Return the role with its current permissions. */
    public function showPermissions(Role $role): JsonResponse
    {
        $role = $this->service->find($role);

        return response()->json(['data' => new RoleResource($role)]);
    }

    /** Replace all permissions on the role with the provided list. */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = $this->service->syncPermissions($role, $request->input('permissions', []));

        return response()->json(['data' => new RoleResource($role)]);
    }
}
