<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\AmountPrecisionUsage;
use App\Services\AmountPrecision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY: lists stored money amounts that carry more decimals than the
 * Amount decimals setting, per table and column.
 *
 * It never writes. Existing records are deliberately left as they were issued;
 * this report is so a difference can be explained, not "fixed". Each module
 * declares its own money columns (AmountPrecisionUsage::moneyColumns()), so
 * unit prices, costs, rates and quantities are never reported.
 */
class PrecisionAudit extends Command
{
    protected $signature = 'precision:audit
        {--samples=3 : Example values to show per column}';

    protected $description = 'Read-only: list stored money amounts with more decimals than the Amount decimals setting';

    public function handle(AmountPrecision $precision): int
    {
        $decimals = $precision->decimals();
        $samples  = max(0, (int) $this->option('samples'));
        $rows     = [];
        $checked  = 0;

        $this->info("Amount decimals: {$decimals}. Read-only scan; nothing is changed.");

        foreach (app()->tagged(AmountPrecisionUsage::TAG) as $usage) {
            foreach ($usage->moneyColumns() as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $this->warn("Declared money column {$table}.{$column} does not exist.");
                        continue;
                    }

                    $checked++;
                    $over = DB::table($table)->whereRaw($this->overPrecision($column, $decimals));
                    $count = (clone $over)->count();

                    if ($count === 0) {
                        continue;
                    }

                    $rows[] = [
                        $table,
                        $column,
                        DB::table($table)->count(),
                        $count,
                        number_format((float) (clone $over)->selectRaw($this->excess($column, $decimals) . ' as m')->orderByDesc('m')->value('m'), 4),
                        implode(' | ', (clone $over)->limit($samples)->pluck($column)->map(fn ($v): string => (string) $v)->all()),
                    ];
                }
            }
        }

        if ($rows === []) {
            $this->info("All {$checked} money columns hold at most {$decimals} decimals.");

            return self::SUCCESS;
        }

        $this->table(['Table', 'Column', 'Rows', "> {$decimals} dp", 'Largest excess', 'Examples'], $rows);
        $this->line(count($rows) . " of {$checked} money columns hold values with more than {$decimals} decimals.");
        $this->line('These are historical values and are left unchanged. New and edited documents round to the setting.');

        return self::SUCCESS;
    }

    /** Portable (MySQL and SQLite): the value differs from itself rounded. */
    private function overPrecision(string $column, int $decimals): string
    {
        return "ROUND({$this->quoted($column)}, {$decimals}) <> {$this->quoted($column)}";
    }

    private function excess(string $column, int $decimals): string
    {
        return "ABS({$this->quoted($column)} - ROUND({$this->quoted($column)}, {$decimals}))";
    }

    /** Column names come from module code, never from input; quoted all the same. */
    private function quoted(string $column): string
    {
        return DB::getQueryGrammar()->wrap($column);
    }
}
