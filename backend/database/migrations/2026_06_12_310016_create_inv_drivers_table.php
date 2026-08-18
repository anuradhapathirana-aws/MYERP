<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_drivers', function (Blueprint $table): void {
            $table->id();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('driver_code', 20)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('nic_number', 50)->nullable();
            $table->date('date_of_birth')->nullable();

            // ── Licence ───────────────────────────────────────────────────
            $table->string('license_number', 50)->unique();
            $table->string('license_type', 50)->nullable();
            $table->date('license_expiry_date')->nullable();

            // ── Contact ───────────────────────────────────────────────────
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();

            // ── Address ───────────────────────────────────────────────────
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            // ── Employment ────────────────────────────────────────────────
            $table->date('hired_date')->nullable();
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_drivers');
    }
};
