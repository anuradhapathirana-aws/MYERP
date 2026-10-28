<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompanyAccessService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\BillPaymentStatus;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Models\Attachment;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillPayment;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Files on Finance documents, for every document type.
 *
 * ── Adding a document type ────────────────────────────────────────────────────
 * Add one entry to TYPES: its model, the permissions that guard viewing and
 * changing its files, and the rules for when files may be added or removed.
 * The routes, storage and checks are shared.
 *
 * ── Security ─────────────────────────────────────────────────────────────────
 *  - Type is checked from the file CONTENT (finfo), not the name or the
 *    browser's claim: pdf, jpg, png only.
 *  - 5 MB per file, 10 files per document.
 *  - Stored under a random name on the private 'local' disk. No public URL;
 *    downloads stream through the controller after permission and company
 *    checks.
 *  - The original name is sanitised and only ever used as a download label.
 */
class AttachmentService
{
    public const MAX_BYTES     = 5 * 1024 * 1024;
    public const MAX_PER_DOC   = 10;
    public const DISK          = 'local';

    /** @var array<string, string> mime => stored extension */
    public const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    /**
     * @var array<string, array{model: class-string<Model>, view: string, edit: string}>
     */
    private const TYPES = [
        Bill::AUDIT_TYPE        => ['model' => Bill::class, 'view' => 'view_bills', 'edit' => 'edit_bills'],
        BillPayment::AUDIT_TYPE => [
            'model' => BillPayment::class, 'view' => 'view_bill_payments', 'edit' => 'edit_bill_payments',
        ],
    ];

    public function __construct(
        private readonly CompanyAccessService $companyAccess,
        private readonly AuditLogger          $audit,
    ) {}

    /** @return Collection<int, Attachment> */
    public function list(string $type, int $id): Collection
    {
        $this->resolve($type, $id, 'view');

        return Attachment::where('attachable_type', $type)->where('attachable_id', $id)->orderBy('id')->get();
    }

    public function upload(string $type, int $id, UploadedFile $file): Attachment
    {
        $document = $this->resolve($type, $id, 'edit');

        if (! $this->canAdd($type, $document)) {
            throw ValidationException::withMessages(['file' => 'Files cannot be added to a voided document.']);
        }

        $mime = $this->detectMime($file);

        if (! isset(self::ALLOWED_MIMES[$mime])) {
            throw ValidationException::withMessages(['file' => 'Only PDF, JPG and PNG files are allowed.']);
        }

        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES || $file->getSize() === 0) {
            throw ValidationException::withMessages(['file' => 'The file must be smaller than 5 MB.']);
        }

        return DB::transaction(function () use ($type, $id, $file, $mime): Attachment {
            $count = Attachment::where('attachable_type', $type)
                ->where('attachable_id', $id)
                ->lockForUpdate()
                ->count();

            if ($count >= self::MAX_PER_DOC) {
                throw ValidationException::withMessages([
                    'file' => 'A document can have at most ' . self::MAX_PER_DOC . ' files.',
                ]);
            }

            $stored = $file->storeAs(
                'finance/attachments/' . $type . '/' . $id,
                Str::random(40) . '.' . self::ALLOWED_MIMES[$mime],
                self::DISK,
            );

            if ($stored === false) {
                throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
            }

            $attachment = Attachment::create([
                'attachable_type' => $type,
                'attachable_id'   => $id,
                'original_name'   => $this->sanitiseName($file->getClientOriginalName(), self::ALLOWED_MIMES[$mime]),
                'stored_path'     => $stored,
                'mime'            => $mime,
                'size'            => (int) $file->getSize(),
                'uploaded_by'     => Auth::id(),
            ]);

            $this->audit->record($type, $id, 'attachment_added', null, [
                'attachment_id' => $attachment->id,
                'file'          => $attachment->original_name,
            ]);

            return $attachment;
        });
    }

    /** @return array{path: string, name: string, mime: string} */
    public function download(Attachment $attachment): array
    {
        $this->resolve($attachment->attachable_type, $attachment->attachable_id, 'view');

        if (! Storage::disk(self::DISK)->exists($attachment->stored_path)) {
            throw new NotFoundHttpException();
        }

        return [
            'path' => Storage::disk(self::DISK)->path($attachment->stored_path),
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
        ];
    }

    public function delete(Attachment $attachment): void
    {
        $document = $this->resolve($attachment->attachable_type, $attachment->attachable_id, 'edit');

        if (! $this->canRemove($attachment->attachable_type, $document)) {
            throw ValidationException::withMessages([
                'file' => 'Files can only be removed while the document is a draft.',
            ]);
        }

        DB::transaction(function () use ($attachment): void {
            $this->audit->record($attachment->attachable_type, $attachment->attachable_id, 'attachment_removed', [
                'attachment_id' => $attachment->id,
                'file'          => $attachment->original_name,
            ]);

            $path = $attachment->stored_path;
            $attachment->delete();

            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Find the document, then check permission and company access.
     * Unknown types and other companies' documents are 404, not 403.
     */
    private function resolve(string $type, int $id, string $ability): Model
    {
        $config = self::TYPES[$type] ?? throw new NotFoundHttpException();

        /** @var Model|null $document */
        $document = $config['model']::query()->find($id);

        if ($document === null) {
            throw new NotFoundHttpException();
        }

        $user = Auth::user();

        if (! $this->companyAccess->canAccess($user instanceof User ? $user : null, $document->company_id)) {
            throw new NotFoundHttpException();
        }

        if (! $user instanceof User || ! $user->can($config[$ability])) {
            throw new AccessDeniedHttpException('You do not have permission to do this.');
        }

        return $document;
    }

    private function canAdd(string $type, Model $document): bool
    {
        return match ($type) {
            Bill::AUDIT_TYPE        => $document->status !== BillStatus::Voided,
            BillPayment::AUDIT_TYPE => $document->status !== BillPaymentStatus::Voided,
            default                 => false,
        };
    }

    private function canRemove(string $type, Model $document): bool
    {
        return match ($type) {
            Bill::AUDIT_TYPE        => $document->status === BillStatus::Draft,
            BillPayment::AUDIT_TYPE => $document->status === BillPaymentStatus::Draft,
            default                 => false,
        };
    }

    private function detectMime(UploadedFile $file): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file->getRealPath());

        return is_string($mime) ? $mime : '';
    }

    /** Keep a readable, safe label: no paths, no control characters. */
    private function sanitiseName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\pL\pN \-_.()]+/u', '_', $base);
        $base = trim(mb_substr($base, 0, 150), ' ._');

        return ($base === '' ? 'file' : $base) . '.' . $extension;
    }
}
