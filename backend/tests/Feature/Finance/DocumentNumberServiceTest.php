<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\Services\DocumentNumberService;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = app(DocumentNumberService::class);
    }

    private function next(string $date): string
    {
        return DB::transaction(fn (): string => $this->numbers->next(DocumentNumberService::TYPE_BILL, new DateTimeImmutable($date)));
    }

    public function test_numbers_run_in_sequence_within_a_year(): void
    {
        $this->assertSame('BILL-2026-0001', $this->next('2026-01-05'));
        $this->assertSame('BILL-2026-0002', $this->next('2026-06-30'));
        $this->assertSame('BILL-2026-0003', $this->next('2026-12-31'));
    }

    public function test_each_year_starts_again_at_one(): void
    {
        $this->next('2026-12-31');
        $this->next('2026-12-31');

        $this->assertSame('BILL-2027-0001', $this->next('2027-01-01'));
        $this->assertSame('BILL-2026-0003', $this->next('2026-12-31'));
    }

    public function test_preview_shows_the_next_number_without_taking_it(): void
    {
        $date = new DateTimeImmutable('2026-03-01');

        $this->assertSame('BILL-2026-0001', $this->numbers->preview(DocumentNumberService::TYPE_BILL, $date));
        $this->assertSame('BILL-2026-0001', $this->numbers->preview(DocumentNumberService::TYPE_BILL, $date));
        $this->assertSame('BILL-2026-0001', $this->next('2026-03-01'));
        $this->assertSame('BILL-2026-0002', $this->numbers->preview(DocumentNumberService::TYPE_BILL, $date));
    }

    public function test_a_number_rolled_back_with_its_transaction_is_reissued(): void
    {
        try {
            DB::transaction(function (): void {
                $this->numbers->next(DocumentNumberService::TYPE_BILL, new DateTimeImmutable('2026-03-01'));

                throw new \RuntimeException('save failed');
            });
        } catch (\RuntimeException) {
        }

        // Nothing was saved, so nothing was printed: the number is free.
        $this->assertSame('BILL-2026-0001', $this->next('2026-03-01'));
    }

    public function test_an_unknown_document_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->numbers->next('nonsense', new DateTimeImmutable('2026-01-01'));
    }
}
