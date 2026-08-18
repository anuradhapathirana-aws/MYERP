<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_sales_orders', function (Blueprint $table): void {
            $table->id();

            $table->string('so_no', 30)->unique();
            $table->string('reference_no', 100)->nullable();

            // Soft links
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sales_person_id');            // users
            $table->unsignedBigInteger('order_taken_by')->nullable(); // users

            // Snapshot of the customer type at order time (reporting stability)
            $table->string('customer_type', 30)->nullable();

            // Header dates
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->date('transaction_date')->nullable();

            $table->string('order_source', 30)->nullable();
            $table->text('delivery_address')->nullable();

            // Workflow status — draft | confirmed | completed | cancelled
            $table->string('status', 30)->default('draft');

            // Financial totals
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('transport_charge', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);

            $table->text('remarks')->nullable();

            // Audit — soft link to users
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('customer_id');
            $table->index('sales_person_id');
            $table->index('order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_sales_orders');
    }
};
