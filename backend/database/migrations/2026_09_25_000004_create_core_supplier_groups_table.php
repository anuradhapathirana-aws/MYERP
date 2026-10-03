<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier (vendor) groups — the SAP "account group" / Dynamics "vendor group"
 * pattern. A group's real job is to supply the DEFAULT posting accounts for a
 * supplier's bills; it never restricts what a supplier may be billed for.
 *
 * Replaces the hardcoded Rule::in(['Trade','Service']) that previously lived in
 * SupplierMasterRequest and which rejected the seeded supplier data outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_supplier_groups', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();

            // Soft links to fin_ledger_accounts.id — deliberately NO foreign
            // keys: the Finance module owns that table and may not be installed.
            $table->unsignedBigInteger('default_payable_account_id')->nullable();
            $table->unsignedBigInteger('default_expense_account_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_supplier_groups');
    }
};
