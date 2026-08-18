<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\GrnAttachment;
use Modules\Inventory\Models\GoodsReceivedNote;

class GrnAttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_grns')->only(['index']);
        $this->middleware('permission:edit_grns')->only(['store', 'destroy']);
    }

    public function index(GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        $attachments = $goodsReceivedNote->attachmentFiles()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'data' => $attachments->map(fn (GrnAttachment $a) => $this->toArray($a))->values(),
        ]);
    }

    public function store(Request $request, GoodsReceivedNote $goodsReceivedNote): JsonResponse
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
            $path = $file->store("grns/{$goodsReceivedNote->id}/attachments", 'public');

            $created[] = GrnAttachment::create([
                'grn_id'    => $goodsReceivedNote->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);
        }

        return response()->json([
            'data' => collect($created)->map(fn (GrnAttachment $a) => $this->toArray($a))->values(),
        ], 201);
    }

    public function destroy(GoodsReceivedNote $goodsReceivedNote, GrnAttachment $attachment): JsonResponse
    {
        abort_if($attachment->grn_id !== $goodsReceivedNote->id, 403);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return response()->json(null, 204);
    }

    private function toArray(GrnAttachment $a): array
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
