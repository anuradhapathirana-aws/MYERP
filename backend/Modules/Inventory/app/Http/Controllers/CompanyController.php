<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CompanyData;
use Modules\Inventory\Http\Requests\CompanyRequest;
use Modules\Inventory\Http\Resources\CompanyResource;
use Modules\Inventory\Models\Company;
use Modules\Inventory\Services\CompanyService;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $service)
    {
        $this->middleware('permission:view_companies')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_companies')->only(['store']);
        $this->middleware('permission:edit_companies')->only(['update']);
        $this->middleware('permission:delete_companies')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Company $c) => (new CompanyResource($c))->toArray(request()))
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

    public function store(CompanyRequest $request): JsonResponse
    {
        $company = $this->service->create(CompanyData::fromRequest($request));

        return response()->json(
            ['data' => (new CompanyResource($company))->toArray(request())],
            201,
        );
    }

    public function show(Company $company): JsonResponse
    {
        $company->loadMissing('industry');

        return response()->json(
            ['data' => (new CompanyResource($company))->toArray(request())],
        );
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $company = $this->service->update($company, CompanyData::fromRequest($request));

        return response()->json(
            ['data' => (new CompanyResource($company))->toArray(request())],
        );
    }

    public function destroy(Company $company): JsonResponse
    {
        $this->service->delete($company);

        return response()->json(null, 204);
    }

    /** Flat list for dropdowns — id + company_name. */
    public function all(): JsonResponse
    {
        $items = Company::orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->company_name])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
