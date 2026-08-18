<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_masters', function (Blueprint $table): void {
            $table->id();

            // General
            $table->string('customer_code', 50)->nullable()->unique();
            $table->string('reference_no', 50)->nullable();
            $table->enum('customer_type', ['Trade', 'Retail', 'Wholesale', 'Corporate'])->nullable();
            $table->string('title', 20)->nullable();
            $table->string('customer_name', 100);
            $table->string('nic_passport_driving_licence', 50)->nullable();
            $table->string('attachments', 255)->nullable();
            $table->string('br_no', 50)->nullable();

            // Contact
            $table->string('customer_mobile', 20)->nullable();
            $table->string('customer_land_line', 20)->nullable();
            $table->string('customer_email', 100)->nullable();
            $table->string('customer_fax', 20)->nullable();

            // Billing address
            $table->string('billing_address_line1', 100)->nullable();
            $table->string('billing_address_line2', 100)->nullable();
            $table->string('billing_address_line3', 100)->nullable();
            $table->string('billing_city', 50)->nullable();
            $table->string('billing_zip_postal', 20)->nullable();
            $table->string('billing_state_province', 50)->nullable();
            $table->string('billing_country', 50)->nullable();

            // Shipping address
            $table->string('shipping_address_line1', 100)->nullable();
            $table->string('shipping_address_line2', 100)->nullable();
            $table->string('shipping_address_line3', 100)->nullable();
            $table->string('shipping_city', 50)->nullable();
            $table->string('shipping_zip_postal', 20)->nullable();
            $table->string('shipping_state_province', 50)->nullable();
            $table->string('shipping_country', 50)->nullable();

            // Sales team
            $table->string('sale_manager', 100)->nullable();
            $table->string('sales_executive', 100)->nullable();
            $table->string('sales_person', 100)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_masters');
    }
};
