<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\PaymentMode;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;
    private PaymentMode $cash;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_customer_receipts', 'create_customer_receipts', 'edit_customer_receipts',
            'delete_customer_receipts', 'confirm_customer_receipts',
            'view_customer_credit_notes',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);

        $this->cash = PaymentMode::create([
            'payment_mode_name' => 'Cash',
            'code'              => 'CASH',
            'is_active'         => true,
        ]);
    }

    /** Seed an issued invoice directly — receipts only depend on inv_invoices rows. */
    private function makeIssuedInvoice(float $grandTotal = 1000.0, ?int $customerId = null): Invoice
    {
        static $seq = 0;
        $seq++;

        return Invoice::create([
            'invoice_no'   => sprintf('INV-TST-%04d', $seq),
            'so_id'        => $seq, // soft link, no FK — any id works for receipt tests
            'do_id'        => null,
            'customer_id'  => $customerId ?? $this->customer->id,
            'invoice_date' => '2026-07-10',
            'due_date'     => '2026-08-10',
            'status'       => 'issued',
            'invoice_type' => 'non_tax',
            'subtotal'     => $grandTotal,
            'grand_total'  => $grandTotal,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function receiptPayload(Invoice $invoice, array $overrides = []): array
    {
        return array_replace_recursive([
            'receipt_date' => '2026-07-17',
            'customer_id'  => $this->customer->id,
            'is_advance'   => false,
            'allocations'  => [[
                'reference_type' => 'invoice',
                'reference_id'   => $invoice->id,
                'receipt_amount' => (float) $invoice->grand_total,
            ]],
            'settlements'  => [[
                'payment_mode_id' => $this->cash->id,
                'amount'          => (float) $invoice->grand_total,
            ]],
        ], $overrides);
    }

    public function test_outstanding_invoices_lists_only_issued_unpaid_invoices(): void
    {
        $issued = $this->makeIssuedInvoice(1500);
        $this->makeIssuedInvoice(999)->update(['status' => 'draft']);
        $this->makeIssuedInvoice(888)->update(['status' => 'paid']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/customer-receipts/outstanding-invoices/{$this->customer->id}")
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame($issued->id, $rows->first()['invoice_id']);
        $this->assertEquals(1500.0, $rows->first()['outstanding']);
    }

    public function test_full_receipt_confirm_marks_invoice_paid(): void
    {
        $invoice = $this->makeIssuedInvoice(2000);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('issued', $invoice->fresh()->status->value);

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.gross_amount', 2000);

        $fresh = $invoice->fresh();
        $this->assertSame('paid', $fresh->status->value);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_partial_receipt_keeps_invoice_issued_and_reduces_outstanding(): void
    {
        $invoice = $this->makeIssuedInvoice(2000);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'allocations' => [['receipt_amount' => 500.0]],
                'settlements' => [['amount' => 500.0]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk();

        $this->assertSame('issued', $invoice->fresh()->status->value);

        $rows = $this->actingAs($this->user)
            ->getJson("/api/v1/customer-receipts/outstanding-invoices/{$this->customer->id}")
            ->json('data');

        $this->assertEquals(1500.0, $rows[0]['outstanding']);
    }

    public function test_discount_writes_off_outstanding_like_cash(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        // 900 cash + 100 discount fully settles the invoice
        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'allocations' => [['discount' => 100.0, 'receipt_amount' => 900.0]],
                'settlements' => [['amount' => 900.0]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.discount_amount', 100);

        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_underfunded_receipt_is_rejected(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'settlements' => [['amount' => 400.0]],
            ]))
            ->assertStatus(422);
    }

    public function test_overpayment_creates_open_credit_note(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        // Receive 1200 against a 1000 invoice — 200 becomes an over_payment credit note
        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'allocations' => [['receipt_amount' => 1200.0]],
                'settlements' => [['amount' => 1200.0]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk();

        $creditNote = CustomerCreditNote::where('customer_id', $this->customer->id)->first();
        $this->assertNotNull($creditNote);
        $this->assertSame('over_payment', $creditNote->credit_type->value);
        $this->assertEquals(200.0, (float) $creditNote->remaining_balance);
        $this->assertSame('open', $creditNote->status->value);
        $this->assertStringStartsWith('CCN-', $creditNote->credit_note_no);

        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_advance_receipt_creates_credit_note_usable_as_setoff(): void
    {
        // 1) Standalone advance of 500
        $advanceId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', [
                'receipt_date'   => '2026-07-17',
                'customer_id'    => $this->customer->id,
                'is_advance'     => true,
                'advance_amount' => 500.0,
                'allocations'    => [],
                'settlements'    => [[
                    'payment_mode_id' => $this->cash->id,
                    'amount'          => 500.0,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$advanceId}/confirm")
            ->assertOk();

        $creditNote = CustomerCreditNote::where('credit_type', 'advance')->firstOrFail();
        $this->assertEquals(500.0, (float) $creditNote->remaining_balance);

        // 2) Settle a 500 invoice entirely with the advance credit note
        $invoice = $this->makeIssuedInvoice(500);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'settlements' => [],
                'setoffs'     => [[
                    'setoff_type'    => 'advance',
                    'credit_note_id' => $creditNote->id,
                    'amount'         => 500.0,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status->value);
        $this->assertSame('exhausted', $creditNote->fresh()->status->value);
        $this->assertEquals(0.0, (float) $creditNote->fresh()->remaining_balance);
    }

    public function test_setoff_exceeding_credit_note_balance_is_rejected(): void
    {
        $creditNote = CustomerCreditNote::create([
            'credit_note_no'    => 'CCN-0001',
            'customer_id'       => $this->customer->id,
            'credit_type'       => 'advance',
            'amount'            => 100,
            'remaining_balance' => 100,
            'status'            => 'open',
        ]);

        $invoice = $this->makeIssuedInvoice(300);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice, [
                'settlements' => [['amount' => 100.0]],
                'setoffs'     => [[
                    'setoff_type'    => 'advance',
                    'credit_note_id' => $creditNote->id,
                    'amount'         => 200.0,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertStatus(422);

        $this->assertSame('issued', $invoice->fresh()->status->value);
        $this->assertEquals(100.0, (float) $creditNote->fresh()->remaining_balance);
    }

    public function test_only_draft_receipts_can_be_edited_confirmed_or_deleted(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/customer-receipts/{$receiptId}", $this->receiptPayload($invoice))
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/customer-receipts/{$receiptId}")
            ->assertStatus(422);
    }

    public function test_receipt_numbers_are_sequential(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-receipts/next-receipt-no')
            ->assertOk()
            ->assertJsonPath('data.receipt_no', 'RCP-0001');

        $invoice = $this->makeIssuedInvoice(1000);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', 'RCP-0001');

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-receipts/next-receipt-no')
            ->assertOk()
            ->assertJsonPath('data.receipt_no', 'RCP-0002');
    }

    public function test_cheque_settlement_requires_six_digit_cheque_number(): void
    {
        $cheque = PaymentMode::create([
            'payment_mode_name'     => 'Cheque',
            'code'                  => 'cheque',
            'requires_reference_no' => true,
            'is_active'             => true,
        ]);

        $invoice = $this->makeIssuedInvoice(1000);

        $chequePayload = fn (?string $chequeNo) => $this->receiptPayload($invoice, [
            'settlements' => [[
                'payment_mode_id' => $cheque->id,
                'amount'          => 1000.0,
                'reference_no'    => $chequeNo,
            ]],
        ]);

        // Missing, too short, too long, and non-numeric cheque numbers are all rejected
        foreach ([null, '12345', '1234567', '12A456'] as $bad) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/customer-receipts', $chequePayload($bad))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['settlements.0.reference_no']);
        }

        // Exactly 6 digits passes; the cash-mode rows of other tests stay unaffected
        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $chequePayload('123456'))
            ->assertCreated();
    }

    public function test_receipt_pdf_downloads_for_confirmed_receipts_only(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        $receiptId = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertCreated()
            ->json('data.id');

        // Draft receipts have no printable document
        $this->actingAs($this->user)
            ->get("/api/v1/customer-receipts/{$receiptId}/pdf")
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")
            ->assertOk();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/customer-receipts/{$receiptId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $receiptNo = CustomerReceipt::findOrFail($receiptId)->receipt_no;
        $this->assertStringContainsString("RCP_{$receiptNo}.pdf", (string) $response->headers->get('content-disposition'));
    }

    public function test_receipt_requires_permission(): void
    {
        $outsider = User::factory()->create(['active_modules' => ['inventory']]);
        $invoice  = $this->makeIssuedInvoice(1000);

        $this->actingAs($outsider)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson('/api/v1/customer-receipts')
            ->assertForbidden();
    }

    public function test_credit_note_list_endpoint_filters_by_customer(): void
    {
        $other = CustomerMaster::create([
            'customer_code' => 'CUS-0002',
            'customer_name' => 'Other Customer',
            'customer_type' => 'Retail',
        ]);

        CustomerCreditNote::create([
            'credit_note_no'    => 'CCN-0001',
            'customer_id'       => $this->customer->id,
            'credit_type'       => 'advance',
            'amount'            => 100,
            'remaining_balance' => 100,
            'status'            => 'open',
        ]);
        CustomerCreditNote::create([
            'credit_note_no'    => 'CCN-0002',
            'customer_id'       => $other->id,
            'credit_type'       => 'advance',
            'amount'            => 50,
            'remaining_balance' => 50,
            'status'            => 'open',
        ]);

        $rows = $this->actingAs($this->user)
            ->getJson("/api/v1/customer-credit-notes?customer_id={$this->customer->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('CCN-0001', $rows[0]['credit_note_no']);
    }

    public function test_receipt_list_shows_customer_snapshot_and_filters(): void
    {
        $invoice = $this->makeIssuedInvoice(1000);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-receipts', $this->receiptPayload($invoice))
            ->assertCreated();

        $rows = $this->actingAs($this->user)
            ->getJson("/api/v1/customer-receipts?status=draft&customer_id={$this->customer->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Test Customer', $rows[0]['customer']['name']);

        $this->assertSame([], $this->actingAs($this->user)
            ->getJson('/api/v1/customer-receipts?status=confirmed')
            ->json('data'));
    }
}
