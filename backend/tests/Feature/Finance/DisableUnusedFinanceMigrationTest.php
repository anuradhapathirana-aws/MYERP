<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Tests\TestCase;

/**
 * The deploy-day guard: a client that never used Finance must not have it
 * switched on by the release that ships it.
 */
class DisableUnusedFinanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_28_000002_disable_finance_module_where_never_used.php');
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_finance_left_on_but_never_used_is_switched_off(): void
    {
        $this->settings()->set('module.finance', true);
        $this->assertTrue($this->settings()->isEnabled('module.finance'));

        $this->migration()->up();

        // Through the cache, which the migration invalidates.
        $this->assertFalse($this->settings()->isEnabled('module.finance'));
        $this->assertSame('false', DB::table('global_settings')->where('key', 'module.finance')->value('value'));
    }

    public function test_an_installation_that_uses_finance_keeps_it_on(): void
    {
        $this->settings()->set('module.finance', true);
        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
        $this->assertTrue(DB::table('fin_ledger_accounts')->exists());

        $this->migration()->up();

        $this->assertTrue($this->settings()->isEnabled('module.finance'));
    }

    public function test_journals_alone_also_count_as_use(): void
    {
        $this->settings()->set('module.finance', true);
        DB::table('fin_ledger_accounts')->delete();
        DB::table('fin_journal_entries')->insert([
            'journal_no' => 'JV-TEST-1', 'journal_date' => '2026-10-01', 'status' => 'posted',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertTrue($this->settings()->isEnabled('module.finance'));
    }

    public function test_other_settings_and_an_already_off_module_are_untouched(): void
    {
        $this->settings()->set('module.finance', false);
        $this->settings()->set('module.inventory', true);

        $this->migration()->up();

        $this->assertFalse($this->settings()->isEnabled('module.finance'));
        $this->assertTrue($this->settings()->isEnabled('module.inventory'));
    }
}
