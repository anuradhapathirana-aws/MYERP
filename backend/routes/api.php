<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GlobalSettingController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// ── Super-admin only ───────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'super_admin'])->group(function (): void {
    Route::get('/settings', [GlobalSettingController::class, 'index']);
    Route::put('/settings/{key}', [GlobalSettingController::class, 'update']);
});

// ── Any authenticated user ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function (): void {
    // Lightweight user lookup for document dropdowns (sales person, order taken by)
    Route::get('users/all', [UserController::class, 'all'])->name('users.all');
});

// ── Permission-gated administration ─────────────────────────────────────────────
// super_admin bypasses every permission check via Gate::before(); the "admin"
// role is granted all of these permissions by RolesAndPermissionsSeeder.
Route::middleware('auth:sanctum')->group(function (): void {
    // Users — granular CRUD permissions (must precede the {user} wildcard binding)
    Route::get('users', [UserController::class, 'index'])
        ->middleware('permission:view_users')->name('users.index');
    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:create_users')->name('users.store');
    Route::get('users/{user}', [UserController::class, 'show'])
        ->middleware('permission:view_users')->name('users.show');
    Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])
        ->middleware('permission:edit_users')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:delete_users')->name('users.destroy');

    // Roles & permissions — all gated by the single manage_roles permission
    Route::middleware('permission:manage_roles')->group(function (): void {
        Route::apiResource('roles', RoleController::class);
        Route::get('roles/{role}/permissions', [RoleController::class, 'showPermissions'])
            ->name('roles.permissions.show');
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
            ->name('roles.permissions.sync');

        // Permissions — grouped index for the UI permission matrix
        Route::get('permissions', [PermissionController::class, 'index'])
            ->name('permissions.index');
    });
});
