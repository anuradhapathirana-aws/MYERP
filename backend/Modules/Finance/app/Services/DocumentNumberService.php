<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Document numbers for every Finance document: BILL-2026-0001 and so on.
 *
 * Each document type registers its prefix in TYPES; the counter itself lives in
 * fin_document_sequences, one row per type per year.
 *
 * next() must run inside the caller's transaction. It locks the counter row,
 * so concurrent saves queue rather than collide, and the number rolls back
 * together with a document that failed to save.
 *
 * Journal numbers (JV-) keep their own generator in JournalPostingService;
 * they predate this and are not migrated.
 */
class DocumentNumberService
{
    public const TYPE_BILL         = 'bill';
    public const TYPE_BILL_PAYMENT = 'bill_payment';

    /** @var array<string, array{prefix: string, padding: int}> */
    private const TYPES = [
        self::TYPE_BILL         => ['prefix' => 'BILL', 'padding' => 4],
        // BP, not PAY: Inventory's supplier payments already use PAY-.
        self::TYPE_BILL_PAYMENT => ['prefix' => 'BP', 'padding' => 4],
    ];

    /** Allocate the next number. Call inside a DB transaction. */
    public function next(string $type, DateTimeInterface $date): string
    {
        $config = $this->config($type);
        $year   = (int) $date->format('Y');

        // Create the year's counter if this is its first document. insertOrIgnore
        // makes two simultaneous "first of the year" saves safe: one inserts, the
        // other is ignored, and both then lock the same row below.
        DB::table('fin_document_sequences')->insertOrIgnore([
            'document_type' => $type,
            'year'          => $year,
            'prefix'        => $config['prefix'],
            'padding'       => $config['padding'],
            'last_number'   => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::table('fin_document_sequences')
            ->where('document_type', $type)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        $number = (int) $row->last_number + 1;

        DB::table('fin_document_sequences')
            ->where('id', $row->id)
            ->update(['last_number' => $number, 'updated_at' => now()]);

        return $this->format((string) $row->prefix, $year, $number, (int) $row->padding);
    }

    /**
     * The number the next document would get. Lock-free, display only: two
     * people opening the form at once see the same preview, and whoever saves
     * first gets it.
     */
    public function preview(string $type, DateTimeInterface $date): string
    {
        $config = $this->config($type);
        $year   = (int) $date->format('Y');

        $last = DB::table('fin_document_sequences')
            ->where('document_type', $type)
            ->where('year', $year)
            ->value('last_number');

        return $this->format($config['prefix'], $year, (int) $last + 1, $config['padding']);
    }

    private function format(string $prefix, int $year, int $number, int $padding): string
    {
        return $prefix . '-' . $year . '-' . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }

    /** @return array{prefix: string, padding: int} */
    private function config(string $type): array
    {
        return self::TYPES[$type]
            ?? throw new InvalidArgumentException("Unknown document type [{$type}].");
    }
}
