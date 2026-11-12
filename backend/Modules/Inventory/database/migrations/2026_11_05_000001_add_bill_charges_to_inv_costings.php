<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Costing "Pull from bills".
 *
 * inv_costing_bill_charges -- each supplier-bill line pulled into a costing, as
 *   it was when pulled (bill no, charge, amount). source_type/source_id point
 *   at the bill line in Finance: a soft link, so Inventory runs without it.
 *   One bill line belongs to one live costing at a time (checked in the
 *   service, since costings are soft-deleted).
 *
 * inv_costing_item_expenses.source -- 'manual' for amounts typed on the form
 *   (estimates), 'bill' for the share of pulled bills. Both can sit on the
 *   same product and expense row, so the unique key gains the source.
 *   Existing rows are all typed amounts and default to 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_bill_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('costing_id')->constrained('inv_costings')->cascadeOnDelete();

            $table->string('source_type', 30)->default('bill_line');
            $table->unsignedBigInteger('source_id');

            // Snapshot for display: the costing shows what it was built from.
            $table->string('bill_no', 30);
            $table->date('bill_date')->nullable();
            $table->string('party_name', 150)->nullable();
            $table->string('charge_name', 100);
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('expense_type_id');
            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->unique(['costing_id', 'source_type', 'source_id'], 'inv_costing_bill_charges_unq');
            $table->index(['source_type', 'source_id'], 'inv_costing_bill_charges_source_idx');
        });

        Schema::table('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->string('source', 10)->default('manual')->after('expense_type_id');
        });

        // Add the new unique first: MySQL may be using the old one for an FK-less
        // index on costing_item_id, and dropping it alone could leave none.
        Schema::table('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->unique(['costing_item_id', 'expense_type_id', 'source'], 'inv_costing_item_expenses_source_unq');
        });

        Schema::table('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->dropUnique(['costing_item_id', 'expense_type_id']);
        });
    }

    public function down(): void
    {
        // Bill rows would collide with typed rows under the old key.
        DB::table('inv_costing_item_expenses')->where('source', 'bill')->delete();

        Schema::table('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->unique(['costing_item_id', 'expense_type_id']);
        });

        Schema::table('inv_costing_item_expenses', function (Blueprint $table): void {
            $table->dropUnique('inv_costing_item_expenses_source_unq');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('inv_costing_bill_charges');
    }
};
