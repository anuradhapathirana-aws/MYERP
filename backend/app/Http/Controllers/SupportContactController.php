<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SupportContactRequest;
use App\Services\SupportContactService;
use Illuminate\Http\JsonResponse;

/** The IT support contact shown in the User Manual. */
class SupportContactController extends Controller
{
    public function __construct(private readonly SupportContactService $contact) {}

    /** GET /api/system/support-contact (any signed-in user) */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->contact->get()]);
    }

    /** PUT /api/system/support-contact (super_admin only) */
    public function update(SupportContactRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->contact->update($request->validated())]);
    }
}
