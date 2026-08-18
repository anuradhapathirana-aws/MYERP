<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_payment_settlements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('inv_supplier_payments')
                ->cascadeOnDelete();

            // Soft link to inv_payment_modes, no FK (mirrors this feature's existing soft-link style)
            $table->unsignedBigInteger('payment_mode_id');

            // Denormalized snapshots so historical settlement lines stay readable
            // even if the payment mode master row is later renamed/deactivated/deleted
            $table->string('payment_mode_code', 30);
            $table->string('payment_mode_name', 50);

            $table->decimal('amount', 15, 4)->default(0);

            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('reference_no', 50)->nullable();
            $table->date('instrument_date')->nullable();
            $table->boolean('is_thirdparty')->default(false);
            $table->text('remark')->nullable();

            $table->timestamps();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_payment_settlements');
    }
};
