<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared bank master. Lives in core (not a module) because both Inventory
 * (customer receipt / supplier payment settlements) and Finance (bank ledger
 * accounts, payments) need the same list of banks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_banks', function (Blueprint $table): void {
            $table->id();

            $table->string('bank_name', 100);
            $table->string('bank_code', 30)->unique();
            $table->string('address', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('bank_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_banks');
    }
};
