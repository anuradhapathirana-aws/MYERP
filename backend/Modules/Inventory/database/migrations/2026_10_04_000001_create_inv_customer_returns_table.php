<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_returns', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. SRN-0001)
            $table->string('return_no', 30)->unique();
            $table->date('return_date');

            // Soft links — the invoice being returned against, and the delivery order
            // whose stock movement this return reverses (invoice ↔ DO is 1:1).
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('do_id')->nullable();

            // Default return store — lines may override it (e.g. a Damaged store)
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('location_id');

            // Workflow status: draft | confirmed
            $table->string('status', 20)->default('draft');

            // Server-computed money effect (set at confirm): applied_to_invoice reduces the
            // invoice's outstanding; the rest becomes an open sales_return credit note.
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('applied_to_invoice', 15, 4)->default(0);
            $table->unsignedBigInteger('credit_note_id')->nullable();

            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index('return_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_returns');
    }
};
