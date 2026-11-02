<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared tax master. Lives in core (not a module) because Inventory (costing,
 * proforma, invoice) and Finance (sales journals, later bills and the VAT
 * return) must read the same rates — and an Inventory-only client still sets
 * its VAT and SSCL here.
 *
 * One row per rate PERIOD: changing VAT from 15 to 18 adds a VAT row with a
 * later effective_from, so a document dated before the change still gets the
 * old rate. The latest row on or before a date decides; an inactive latest row
 * means the tax is switched off (rate 0) from that date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_taxes', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('tax_type', 10); // App\Enums\TaxType
            $table->decimal('rate', 5, 2);
            $table->date('effective_from');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // seeded rows: code and type locked, never deleted

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['code', 'effective_from']);
            $table->index('tax_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_taxes');
    }
};
