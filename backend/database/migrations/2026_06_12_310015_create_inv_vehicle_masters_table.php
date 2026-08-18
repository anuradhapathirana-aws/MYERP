<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_vehicle_masters', function (Blueprint $table): void {
            $table->id();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('vehicle_code', 20)->unique();
            $table->string('registration_number', 50)->unique();

            // ── Specifications ────────────────────────────────────────────
            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 50)->nullable();
            $table->enum('vehicle_type', ['Car', 'Van', 'Truck', 'Bus', 'Motorcycle', 'Heavy Truck'])->nullable();
            $table->enum('fuel_type', ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG'])->nullable();
            $table->string('engine_number', 100)->nullable();
            $table->string('chassis_number', 100)->nullable();
            $table->unsignedTinyInteger('seating_capacity')->nullable();
            $table->decimal('payload_capacity', 10, 2)->nullable();

            // ── Compliance ────────────────────────────────────────────────
            $table->string('insurance_policy_no', 50)->nullable();
            $table->date('insurance_expiry_date')->nullable();
            $table->date('road_tax_expiry_date')->nullable();
            $table->date('emission_test_expiry_date')->nullable();

            // ── Assignment (soft ref — no FK constraint) ──────────────────
            $table->unsignedBigInteger('assigned_driver_id')->nullable();   // inv_drivers.id

            // ── Misc ──────────────────────────────────────────────────────
            $table->enum('status', ['active', 'inactive', 'under_maintenance'])->default('active');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_vehicle_masters');
    }
};
