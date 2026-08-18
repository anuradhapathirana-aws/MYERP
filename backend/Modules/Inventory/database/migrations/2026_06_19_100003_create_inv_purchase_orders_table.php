<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_purchase_orders', function (Blueprint $table): void {
            $table->id();

            $table->string('po_no', 30)->unique();
            $table->string('reference_no', 100)->nullable();

            // Soft links
            $table->unsignedBigInteger('pr_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('location_id')->nullable();

            // Header dates
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();

            // Payment & logistics
            $table->string('location', 255)->nullable();
            $table->string('payment_terms', 100)->nullable();
            $table->string('contact_person_name', 100)->nullable();
            $table->string('contact_person_phone', 30)->nullable();
            $table->boolean('is_consignment')->default(false);

            // Addresses
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();

            // Workflow status — draft | sent | confirmed | partially_received | completed | cancelled
            $table->string('status', 30)->default('draft');

            // Financial totals
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);

            $table->text('remarks')->nullable();

            // Audit — soft link to users
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('supplier_id');
            $table->index('store_id');
            $table->index('order_date');
            $table->index('pr_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_purchase_orders');
    }
};
