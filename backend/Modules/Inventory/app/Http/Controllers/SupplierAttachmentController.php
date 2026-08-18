<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\SupplierAttachment;
use Modules\Inventory\Models\SupplierMaster;

class SupplierAttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_supplier_masters')->only(['index']);
        $this->middleware('permission:edit_supplier_masters')->only(['store', 'destroy']);
    }

    public function index(SupplierMaster $supplierMaster): JsonResponse
    {
        $attachments = $supplierMaster->attachmentFiles()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'data' => $attachments->map(fn (SupplierAttachment $a) => $this->toArray($a))->values(),
        ]);
    }

    public function store(Request $request, SupplierMaster $supplierMaster): JsonResponse
    {
        $request->validate([
            'files'   => ['required', 'array', 'max:20'],
            'files.*' => ['required', 'file', 'max:10240'],
        ]);

        $created = [];

        foreach ($request->file('files') as $file) {
            $path = $file->store("suppliers/{$supplierMaster->id}/attachments", 'public');

            $created[] = SupplierAttachment::create([
                'supplier_master_id' => $supplierMaster->id,
                'file_name'          => $file->getClientOriginalName(),
                'file_path'          => $path,
                'file_size'          => $file->getSize(),
                'mime_type'          => $file->getMimeType(),
            ]);
        }

        return response()->json([
            'data' => collect($created)->map(fn (SupplierAttachment $a) => $this->toArray($a))->values(),
        ], 201);
    }

    public function destroy(SupplierMaster $supplierMaster, SupplierAttachment $attachment): JsonResponse
    {
        abort_if($attachment->supplier_master_id !== $supplierMaster->id, 403);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function toArray(SupplierAttachment $a): array
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
