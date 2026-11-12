<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Services\CompanyAccessService;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\LedgerAccount;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Cash, Bank & Cheques on the home dashboard: posted balances per cash and
 * bank account, and confirmed bills still owed, by due date. Only for users
 * who may see the Trial Balance, and only their own companies.
 */
class DashboardTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/dashboard/finance/cash';

    private LedgerAccount $cash;
    private LedgerAccount $bank;
    private int $fundingSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [...$this->billPermissions, 'view_dashboard', 'view_finance_reports'];

        foreach (['view_dashboard', 'view_finance_reports'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->setUpBills();

        $this->cash = $this->ledger('Main Cash', 'Cash & Cash Equivalents', CashBookType::CashBook, $this->companyA);
        $this->bank = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
    }

    private function fund(LedgerAccount $account, float $amount): void
    {
        app(JournalPoster::class)->post(new JournalRequest(
            sourceType:  'test_funding',
            sourceId:    ++$this->fundingSeq,
            journalDate: new DateTimeImmutable('2026-10-01'),
            lines:       [
                JournalLineRequest::debit($account->id, $amount),
                JournalLineRequest::credit(PostingRole::OPENING_BALANCE_EQUITY, $amount),
            ],
            companyId:   $this->companyA,
        ));
    }

    public function test_it_shows_cash_and_bank_balances_and_bills_by_due_date(): void
    {
        $this->fund($this->cash, 5000);
        $this->fund($this->bank, 20000);

        // Today is 2026-10-26 (BillFixtures).
        $this->confirmedBill(['document_no' => 'LATE-1', 'transaction_date' => '2026-10-10', 'document_date' => '2026-10-08', 'due_date' => '2026-10-20']);
        $this->confirmedBill(['document_no' => 'SOON-1', 'due_date' => '2026-10-30']);
        $this->confirmedBill(['document_no' => 'LATER-1', 'due_date' => '2026-11-19']);
        $this->createBill(['document_no' => 'DRAFT-1', 'due_date' => '2026-10-21']);   // draft: never owed

        $data = $this->actingAs($this->user)->getJson(self::URL)->assertOk()->json('data');

        $this->assertEquals(5000, $data['cash_total']);
        $this->assertEquals(20000, $data['bank_total']);
        $this->assertEquals(0, $data['cheques_in_hand']);

        $bills = $data['bills'];
        $this->assertSame(3, $bills['open_count']);
        $this->assertEquals(45001.5, $bills['open_total']);
        $this->assertSame(1, $bills['overdue_count']);
        $this->assertSame(1, $bills['due_soon_count']);
        $this->assertEquals(15000.5, $bills['due_soon_total']);
        $this->assertTrue($bills['upcoming'][0]['overdue']);
        $this->assertSame('2026-10-20', $bills['upcoming'][0]['due_date']);
        $this->assertSame('Ceylon Electricity Board', $bills['upcoming'][0]['supplier']);
    }

    public function test_a_user_restricted_to_another_company_sees_none_of_it(): void
    {
        $this->fund($this->cash, 5000);
        $this->confirmedBill();

        $restricted = $this->makeUser();
        app(CompanyAccessService::class)->sync($restricted, [$this->companyB]);

        $data = $this->actingAs($restricted)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([], $data['accounts']);
        $this->assertSame(0, $data['bills']['open_count']);
    }

    public function test_it_needs_the_financial_reports_permission(): void
    {
        $this->actingAs($this->makeUser(['view_dashboard']))->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->makeUser(['view_finance_reports']))->getJson(self::URL)->assertForbidden();
    }
}
