<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These prices are now denominated per the product's stocking (base) UOM, so they
     * shrink by the conversion factor: 250.00 per Kg becomes 0.25 per g. decimal(15,4)
     * cannot hold the tail of that division — a cheap bulk item priced per tonne would
     * round to 0.0000 per gram and value the stock at nothing. 8 dp gives the headroom.
     *
     * @var array<string, array<int, string>> table => price columns
     */
    private const COLUMNS = [
        'inv_stock_transactions'        => ['unit_price'],
        'inv_goods_received_note_items' => ['unit_price'],
        'inv_sales_order_items'         => ['unit_price'],
        'inv_batches'                   => ['unit_cost'],
        'inv_grn_item_batches'          => ['unit_cost'],
    ];

    public function up(): void
    {
        $this->setScale(20, 8);
    }

    public function down(): void
    {
        $this->setScale(15, 4);
    }

    private function setScale(int $total, int $places): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns, $total, $places): void {
                foreach ($columns as $column) {
                    // inv_stock_transactions.unit_price is the only nullable one.
                    $definition = $blueprint->decimal($column, $total, $places);

                    if ($table === 'inv_stock_transactions') {
                        $definition->nullable();
                    } else {
                        $definition->default(0);
                    }

                    $definition->change();
                }
            });
        }
    }
};
