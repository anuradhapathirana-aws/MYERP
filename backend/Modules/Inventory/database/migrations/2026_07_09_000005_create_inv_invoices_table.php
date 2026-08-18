<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_invoices', function (Blueprint $table): void {
            $table->id();

            $table->string('invoice_no', 30)->unique();

            // Soft links — so_id is ALWAYS set; do_id NULL = direct-SO (advance) invoice.
            // One live (non-cancelled) invoice per DO is enforced in the service
            // under lockForUpdate (MySQL has no partial unique indexes).
            $table->unsignedBigInteger('so_id');
            $table->unsignedBigInteger('do_id')->nullable();
            $table->unsignedBigInteger('customer_id'); // snapshot from SO

            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // Workflow status — draft | issued | paid | cancelled
            $table->string('status', 30)->default('draft');

            // Financial totals — same math as sales orders
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('transport_charge', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);

            $table->text('delivery_address')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('so_id');
            $table->index('do_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_invoices');
    }
};
