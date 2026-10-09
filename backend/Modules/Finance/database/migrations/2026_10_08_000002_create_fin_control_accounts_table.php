<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 2 of the chart of accounts.
 *
 * A real FK to fin_account_categories is correct here: both tables belong to
 * the Finance module, so the "no hard FK across module boundaries" rule does
 * not apply. restrictOnDelete rather than cascade — silently destroying a
 * subtree of the chart of accounts is never the right outcome. The service
 * layer turns that restriction into a readable 422 before the DB ever sees it.
 *
 * `code` is 5 digits: the parent category code (3) + a 2-digit sequence within
 * that category. 101 => 10101, 10102, …
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_control_accounts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('account_category_id')
                ->constrained('fin_account_categories')
                ->restrictOnDelete();

            $table->string('code', 5)->unique();
            $table->string('control_account_name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Named explicitly — the generated name would be 66 characters and
            // MySQL caps identifiers at 64.
            $table->index(['account_category_id', 'control_account_name'], 'fin_ctrl_acc_cat_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_control_accounts');
    }
};
