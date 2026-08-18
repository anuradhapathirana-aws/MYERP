<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costing redesign: margin replaces "value addition", SSCL/VAT become
 * per-costing on/off toggles that cascade into each line's selling price.
 * Legacy summary columns (material_cost, value_addition_*) are kept
 * non-destructively; the service now derives them from the GRN lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_costings', function (Blueprint $table): void {
            $table->decimal('default_margin_pct', 5, 2)->default(0)->after('total_landed_cost');
            $table->boolean('apply_sscl')->default(false)->after('sscl_pct');
            $table->boolean('apply_vat')->default(false)->after('vat_pct');
        });
    }

    public function down(): void
    {
        Schema::table('inv_costings', function (Blueprint $table): void {
            $table->dropColumn(['default_margin_pct', 'apply_sscl', 'apply_vat']);
        });
    }
};
