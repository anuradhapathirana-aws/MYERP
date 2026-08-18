<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_delivery_orders', function (Blueprint $table): void {
            $table->id();

            $table->string('do_no', 30)->unique();

            // Soft links
            $table->unsignedBigInteger('so_id');
            $table->unsignedBigInteger('customer_id'); // snapshot from SO for list filtering
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();

            // Source store/location for MANUAL (non-roll) lines only —
            // roll lines ship from each piece's own store/location.
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            $table->date('delivery_date');
            $table->text('delivery_address')->nullable();

            // Workflow status — draft | confirmed | cancelled
            $table->string('status', 30)->default('draft');

            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('so_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('delivery_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_delivery_orders');
    }
};
