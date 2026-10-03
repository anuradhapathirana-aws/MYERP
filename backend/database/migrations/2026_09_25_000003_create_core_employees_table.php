<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal shared employee master.
 *
 * Standard ERPs keep a basic worker record in the shared layer (SAP exposes the
 * HCM personnel master to FI; Dynamics puts Worker in a shared HR entity) and
 * let the sellable HR module add contracts, leave and payroll on top. Finance
 * needs it now for petty cash custodians, requestors and payees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_employees', function (Blueprint $table): void {
            $table->id();

            $table->string('employee_code', 30)->unique();
            $table->string('employee_name', 100);
            $table->string('designation', 100)->nullable();
            $table->string('department', 100)->nullable();

            // Soft link to inv_locations.id — no FK. The locations table is
            // pending a rename to core_*, and cross-boundary links stay soft.
            $table->unsignedBigInteger('location_id')->nullable()->index();

            $table->string('mobile', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_employees');
    }
};
