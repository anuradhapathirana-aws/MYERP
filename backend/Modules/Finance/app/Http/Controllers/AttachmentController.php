<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Models\Attachment;
use Modules\Finance\Services\AttachmentService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Files on any Finance document.
 *
 * No permission middleware here on purpose: which permission applies depends
 * on the document type, so AttachmentService checks it per type (view_bills,
 * edit_bills ...) together with company access.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $service) {}

    public function index(string $type, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->service->list($type, $id)->map(fn (Attachment $a): array => $this->present($a))->values()->all(),
        ]);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        // Laravel's own checks first (present, a real upload, size); the
        // service then checks the content type with finfo.
        $request->validate([
            'file' => ['required', 'file', 'max:' . (AttachmentService::MAX_BYTES / 1024)],
        ]);

        $attachment = $this->service->upload($type, $id, $request->file('file'));

        return response()->json(['data' => $this->present($attachment)], 201);
    }

    public function download(Attachment $attachment): BinaryFileResponse
    {
        $file = $this->service->download($attachment);

        return response()->download($file['path'], $file['name'], [
            'Content-Type'           => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $this->service->delete($attachment);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(Attachment $a): array
    {
        return [
            'id'            => $a->id,
            'original_name' => $a->original_name,
            'mime'          => $a->mime,
            'size'          => $a->size,
            'created_at'    => $a->created_at?->toISOString(),
        ];
    }
}
