<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\UserData;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    /** GET /users/all — lightweight lookup for document dropdowns (any authenticated user) */
    public function all(): JsonResponse
    {
        return response()->json([
            'data' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (User $u) => (new UserResource($u))->toArray(request()))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->service->create(UserData::fromRequest($request));

        return response()->json(
            ['data' => (new UserResource($user))->toArray(request())],
            201,
        );
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(
            ['data' => (new UserResource($this->service->find($user)))->toArray(request())],
        );
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $updated = $this->service->update($user, UserData::fromRequest($request));

        return response()->json(
            ['data' => (new UserResource($updated))->toArray(request())],
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $this->service->delete($user);

        return response()->json(null, 204);
    }
}
