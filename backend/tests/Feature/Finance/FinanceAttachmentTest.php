<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\CompanyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Finance\Models\Attachment;
use Modules\Finance\Services\AttachmentService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

class FinanceAttachmentTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();

        Storage::fake(AttachmentService::DISK);
    }

    /** A real (tiny) PDF, so finfo sees application/pdf from the bytes. */
    private function pdf(string $name = 'invoice.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function upload(int $billId, UploadedFile $file, $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->post("/api/v1/finance-attachments/bill/{$billId}", ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_a_pdf_is_stored_privately_under_a_random_name(): void
    {
        $bill = $this->createBill();

        $response = $this->upload($bill->id, $this->pdf('CEB Oct ../../evil.pdf'))->assertCreated();

        $attachment = Attachment::findOrFail($response->json('data.id'));

        $this->assertSame('application/pdf', $attachment->mime);
        $this->assertStringNotContainsString('evil', $attachment->stored_path);
        $this->assertStringNotContainsString('..', $attachment->original_name);
        Storage::disk(AttachmentService::DISK)->assertExists($attachment->stored_path);

        // Never exposed to the client.
        $this->assertArrayNotHasKey('stored_path', $response->json('data'));
    }

    public function test_a_file_pretending_to_be_a_pdf_is_refused(): void
    {
        $bill = $this->createBill();
        $fake = UploadedFile::fake()->createWithContent('invoice.pdf', '<?php echo "pwned";');

        $this->upload($bill->id, $fake)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, Attachment::count());
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $bill = $this->createBill();
        $big  = UploadedFile::fake()->create('big.pdf', 6 * 1024, 'application/pdf');

        $this->upload($bill->id, $big)->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_at_most_ten_files_per_document(): void
    {
        $bill = $this->createBill();

        for ($i = 0; $i < 10; $i++) {
            $this->upload($bill->id, $this->pdf("f{$i}.pdf"))->assertCreated();
        }

        $this->upload($bill->id, $this->pdf('eleven.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_download_needs_view_permission_and_company_access(): void
    {
        $bill = $this->createBill();
        $id   = $this->upload($bill->id, $this->pdf())->json('data.id');

        $this->actingAs($this->user)->get("/api/v1/finance-attachments/{$id}/download")->assertOk();

        $noView = $this->makeUser(['create_bills']);
        $this->actingAs($noView)->getJson("/api/v1/finance-attachments/{$id}/download")->assertForbidden();

        $betaOnly = $this->makeUser();
        app(CompanyAccessService::class)->sync($betaOnly, [$this->companyB]);
        $this->actingAs($betaOnly)->getJson("/api/v1/finance-attachments/{$id}/download")->assertNotFound();
    }

    public function test_files_can_be_removed_only_while_draft(): void
    {
        $bill  = $this->createBill();
        $first = $this->upload($bill->id, $this->pdf('a.pdf'))->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/v1/finance-attachments/{$first}")->assertNoContent();

        $second = $this->upload($bill->id, $this->pdf('b.pdf'))->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertOk();

        $this->actingAs($this->user)->deleteJson("/api/v1/finance-attachments/{$second}")
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        // Adding supporting files after confirm is still allowed.
        $this->upload($bill->id, $this->pdf('c.pdf'))->assertCreated();
    }

    public function test_an_unknown_document_type_is_404(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/finance-attachments/payroll/1')->assertNotFound();
    }

    public function test_attachment_changes_are_audited(): void
    {
        $bill = $this->createBill();
        $this->upload($bill->id, $this->pdf())->assertCreated();

        $events = $this->actingAs($this->user)->getJson("/api/v1/bills/{$bill->id}/history")->json('data.*.event');

        $this->assertContains('attachment_added', $events);
    }
}
