<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the duplicated supplier group from supplier payments.
 *
 * inv_supplier_payments.supplier_type held a copy of the supplier's group as
 * free text. It drove no posting and was never filtered or searched — purely
 * descriptive — while the payment already stores supplier_id, so the group is
 * always one join away. Keeping the copy only created two versions that could
 * disagree, and made "filter by group" break silently whenever a group was
 * renamed. The group is now read live through supplier_id.
 *
 * Snapshotting belongs on values that drive an accounting posting (the ledger
 * account and amounts on a GL line), not on descriptive partner attributes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inv_supplier_payments', 'supplier_type')) {
            return;
        }

        Schema::table('inv_supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('supplier_type');
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_payments', function (Blueprint $table): void {
            $table->string('supplier_type', 50)->nullable()->after('reference_no');
        });
    }
};
