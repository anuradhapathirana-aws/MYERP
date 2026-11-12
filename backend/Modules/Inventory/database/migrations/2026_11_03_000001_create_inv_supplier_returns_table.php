<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_returns', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. PRN-0001)
            $table->string('return_no', 30)->unique();
            $table->date('return_date');

            // Soft links — the supplier, and the confirmed GRN the goods go back against
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('grn_id');

            // Store the quantity-tracked lines leave from (rolls leave from wherever they sit)
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('location_id');

            // Workflow status: draft | confirmed
            $table->string('status', 20)->default('draft');

            // Server-computed money effect (set at confirm): applied_to_grn reduces the GRN's
            // outstanding; the rest becomes an open supplier_return credit note.
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('applied_to_grn', 15, 4)->default(0);
            $table->unsignedBigInteger('credit_note_id')->nullable();

            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('supplier_id');
            $table->index('grn_id');
            $table->index('status');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_returns');
    }
};
