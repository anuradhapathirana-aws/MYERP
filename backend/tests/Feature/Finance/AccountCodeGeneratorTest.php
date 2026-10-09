<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Services\AccountCodeGenerator;
use Tests\TestCase;

/**
 * The code scheme is the backbone of the chart of accounts: the code carries
 * the whole path, and sorting by it yields a correctly ordered Trial Balance.
 * These tests pin the rules that make that true.
 */
class AccountCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private AccountCodeGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(AccountCodeGenerator::class);
    }

    public function test_each_account_type_owns_its_own_leading_digit(): void
    {
        $expected = [
            'asset'     => '101',
            'liability' => '201',
            'equity'    => '301',
            'income'    => '401',
            'expense'   => '501',
        ];

        foreach ($expected as $type => $code) {
            $this->assertSame(
                $code,
                $this->generator->nextCategoryCode(AccountType::from($type)),
                "The first {$type} category must be {$code}.",
            );
        }
    }

    public function test_sequences_are_independent_per_parent(): void
    {
        $assets      = $this->category(AccountType::Asset, 'Non-Current Assets');       // 101
        $liabilities = $this->category(AccountType::Liability, 'Current Liabilities');  // 201

        $this->assertSame('101', $assets->code);
        $this->assertSame('201', $liabilities->code);

        // Creating a liability category must not advance the asset sequence.
        $this->assertSame('102', $this->generator->nextCategoryCode(AccountType::Asset));
        $this->assertSame('202', $this->generator->nextCategoryCode(AccountType::Liability));
    }

    public function test_the_code_spells_out_the_full_path(): void
    {
        $category = $this->category(AccountType::Asset, 'Current Assets');   // 101
        $control  = $this->control($category, 'Cash & Cash Equivalents');    // 10101
        $ledger   = $this->generator->nextLedgerAccountCode($control);       // 10101001

        $this->assertSame('101', $category->code);
        $this->assertSame('10101', $control->code);
        $this->assertSame('10101001', $ledger);

        // Each level is a strict prefix of the next — which is what lets a
        // report roll ledgers up to controls with a LIKE, and what makes the
        // ordering meaningful.
        $this->assertStringStartsWith($category->code, $control->code);
        $this->assertStringStartsWith($control->code, $ledger);
    }

    /**
     * The rule that protects the audit trail. An account code printed on a
     * journal must never later belong to a different account.
     */
    public function test_a_deleted_code_is_never_reissued(): void
    {
        $this->category(AccountType::Asset, 'First');   // 101
        $second = $this->category(AccountType::Asset, 'Second');  // 102
        $this->category(AccountType::Asset, 'Third');   // 103

        $second->delete(); // soft delete

        $this->assertSame(
            '104',
            $this->generator->nextCategoryCode(AccountType::Asset),
            'The next code must come from MAX(code), not COUNT(*) — 102 is spent forever.',
        );
    }

    public function test_sequence_is_zero_padded_to_a_fixed_width(): void
    {
        $category = $this->category(AccountType::Asset, 'Current Assets');

        // Nine control accounts, so the tenth crosses the padding boundary.
        for ($i = 1; $i <= 9; $i++) {
            $this->control($category, "Control {$i}");
        }

        $this->assertSame('10109', ControlAccount::orderByDesc('code')->first()->code);
        $this->assertSame('10110', $this->generator->nextControlAccountCode($category));

        // Fixed width is what makes MAX() on a varchar numerically correct.
        $this->assertSame(5, strlen($this->generator->nextControlAccountCode($category)));
    }

    public function test_exhausting_a_sequence_raises_a_readable_error(): void
    {
        $category = $this->category(AccountType::Asset, 'Current Assets');

        // Jump straight to the ceiling rather than creating 99 rows.
        DB::table('fin_control_accounts')->insert([
            'account_category_id'  => $category->id,
            'code'                 => '10199',
            'control_account_name' => 'Last One',
            'is_active'            => true,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('99 control accounts');

        $this->generator->nextControlAccountCode($category);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function category(AccountType $type, string $name): AccountCategory
    {
        $category = new AccountCategory([
            'account_type' => $type, 'category_name' => $name, 'is_active' => true,
        ]);
        $category->code = $this->generator->nextCategoryCode($type);
        $category->save();

        return $category;
    }

    private function control(AccountCategory $category, string $name): ControlAccount
    {
        $control = new ControlAccount([
            'account_category_id' => $category->id, 'control_account_name' => $name, 'is_active' => true,
        ]);
        $control->code = $this->generator->nextControlAccountCode($category);
        $control->save();

        return $control;
    }
}
