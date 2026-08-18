<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_goods_received_notes', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. GRN-2026-0001)
            $table->string('grn_no', 30)->unique();

            // Optional manual reference
            $table->string('reference_no', 100)->nullable();

            // Linked PO — soft link, nullable (direct GRN without PO is allowed)
            $table->unsignedBigInteger('po_id')->nullable();

            // Supplier — soft link, nullable
            $table->unsignedBigInteger('supplier_id')->nullable();

            // Receiving dates
            $table->date('grn_date');
            $table->date('transaction_date')->nullable();

            // Destination store & location — soft links, nullable
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            // Workflow status: draft | confirmed
            $table->string('status', 20)->default('draft');

            // Financial total
            $table->decimal('total_amount', 15, 4)->default(0);

            // Notes
            $table->text('remarks')->nullable();
            $table->string('payment_terms', 100)->nullable();
            $table->json('attachments')->nullable();

            // Audit — soft link to users
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('po_id');
            $table->index('supplier_id');
            $table->index('store_id');
            $table->index('status');
            $table->index('grn_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_goods_received_notes');
    }
};
