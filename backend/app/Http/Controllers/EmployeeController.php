<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\EmployeeData;
use App\Http\Requests\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $service)
    {
        $this->middleware('permission:view_employees')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_employees')->only(['store']);
        $this->middleware('permission:edit_employees')->only(['update']);
        $this->middleware('permission:delete_employees')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'department', 'location_id', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => EmployeeResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function all(): JsonResponse
    {
        return response()->json(['data' => EmployeeResource::collection($this->service->all())]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->service->create(EmployeeData::fromRequest($request));

        return response()->json(['data' => (new EmployeeResource($employee))->toArray($request)], 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json([
            'data' => (new EmployeeResource($employee->load('location:id,location_name')))->toArray(request()),
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->service->update($employee, EmployeeData::fromRequest($request));

        return response()->json(['data' => (new EmployeeResource($employee))->toArray($request)]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->service->delete($employee);

        return response()->json(null, 204);
    }
}
