<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax codes: what staff pick on a document line.
 *
 * core_taxes holds the RATES set by law, by date (VAT 18 % from 2024-01-01).
 * A tax code says WHICH of those taxes apply to a line, in what order, and
 * whether each one can be claimed back:
 *
 *   VAT-IN       VAT                      -> claimable (Input VAT)
 *   SSCL-VAT-IN  SSCL_PUR, then VAT on top -> SSCL is cost, VAT claimable
 *   NO-TAX-INV   (none)                   -> the whole price is cost
 *
 * Components point at a core_taxes CODE, not a row id, so a rate change (a new
 * VAT row with a later date) flows into every code without touching it.
 *
 * vat_category tells the future VAT return how to report the line — Exempt,
 * Zero-rated and No-Tax-Invoice are all 0 % but are reported differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_tax_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('scope', 10)->default('purchase');   // App\Enums\TaxCodeScope
            $table->string('vat_category', 20);                 // App\Enums\VatCategory
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);       // seeded: code and components locked
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['scope', 'is_active']);
        });

        Schema::create('core_tax_code_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_code_id')->constrained('core_tax_codes')->cascadeOnDelete();
            $table->string('tax_code_ref', 20);                 // core_taxes.code
            $table->unsignedTinyInteger('sequence');
            $table->boolean('is_compound')->default(false);     // charged on base + earlier taxes
            $table->boolean('is_recoverable')->default(false);  // claimable as input tax
            $table->timestamps();

            $table->unique(['tax_code_id', 'sequence']);
            $table->index('tax_code_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_tax_code_components');
        Schema::dropIfExists('core_tax_codes');
    }
};
