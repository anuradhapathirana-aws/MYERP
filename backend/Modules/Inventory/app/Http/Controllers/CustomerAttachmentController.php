<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\CustomerAttachment;
use Modules\Inventory\Models\CustomerMaster;

class CustomerAttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_customer_masters')->only(['index']);
        $this->middleware('permission:edit_customer_masters')->only(['store']);
        $this->middleware('permission:delete_customer_masters')->only(['destroy']);
    }

    public function index(CustomerMaster $customerMaster): JsonResponse
    {
        $attachments = $customerMaster->attachmentFiles()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'data' => $attachments->map(fn (CustomerAttachment $a) => $this->toArray($a))->values(),
        ]);
    }

    public function store(Request $request, CustomerMaster $customerMaster): JsonResponse
    {
        $request->validate([
            'files'   => ['required', 'array', 'max:20'],
            'files.*' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,bmp,svg,pdf',
            ],
        ]);

        $created = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store("customers/{$customerMaster->id}/attachments", 'public');

            $created[] = CustomerAttachment::create([
                'customer_master_id' => $customerMaster->id,
                'file_name'          => $file->getClientOriginalName(),
                'file_path'          => $path,
                'file_size'          => $file->getSize(),
                'mime_type'          => $file->getMimeType(),
            ]);
        }

        return response()->json([
            'data' => collect($created)->map(fn(CustomerAttachment $a) => $this->toArray($a)),
        ], 201);
    }

    public function destroy(CustomerMaster $customerMaster, CustomerAttachment $attachment): JsonResponse
    {
        abort_if($attachment->customer_master_id !== $customerMaster->id, 403);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function toArray(CustomerAttachment $a): array
    {
        return [
            'id'        => $a->id,
            'file_name' => $a->file_name,
            'file_path' => $a->file_path,
            'file_size' => $a->file_size,
            'mime_type' => $a->mime_type,
            'url'       => '/storage/' . $a->file_path,
        ];
    }
}
