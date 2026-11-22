<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Contracts\AmountPrecisionUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AmountPrecisionUsage for a module that lists its document tables and money
 * columns. A module extends this and fills in the two maps; adding a document
 * type is one line in each.
 *
 * A table that does not exist (module partly migrated, test database) is
 * skipped rather than failing the settings screen.
 */
abstract class TableAmountPrecisionUsage implements AmountPrecisionUsage
{
    /** @return array<string, array{0: string, 1: string}> table => [singular, plural] */
    abstract protected function documents(): array;

    public function usedBy(): ?string
    {
        $found = [];

        foreach ($this->documents() as $table => [$one, $many]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)->count();

            if ($count > 0) {
                $found[] = $count . ' ' . ($count === 1 ? $one : $many);
            }
        }

        return $found === [] ? null : implode(', ', $found);
    }
}
