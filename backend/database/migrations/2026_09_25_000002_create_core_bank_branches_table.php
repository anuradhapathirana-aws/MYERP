<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank branches. A real FK to core_banks is used here because both tables live
 * in core — the "no hard FK" rule applies only across MODULE boundaries, and
 * core is always installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_bank_branches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_id')
                ->constrained('core_banks')
                ->cascadeOnDelete();

            $table->string('branch_name', 100);
            $table->string('branch_code', 30);
            $table->string('swift_code', 20)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Branch codes are unique within a bank, not globally.
            $table->unique(['bank_id', 'branch_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_bank_branches');
    }
};
