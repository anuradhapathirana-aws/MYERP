<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_payment_modes', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_mode_name', 50)->unique();

            // Stable identifier used by backend/frontend logic ('cash'|'cheque'|'card'|'setoff')
            $table->string('code', 30)->unique();

            // Drive which extra fields the settlement form shows for this mode
            $table->boolean('requires_bank_details')->default(false);
            $table->boolean('requires_reference_no')->default(false);
            $table->boolean('requires_date')->default(false);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_payment_modes');
    }
};
