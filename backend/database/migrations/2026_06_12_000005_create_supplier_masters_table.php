<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_masters', function (Blueprint $table): void {
            $table->id();

            // General
            $table->string('supplier_code', 50)->nullable()->unique();
            $table->string('reference_no', 50)->nullable();
            $table->string('supplier_type', 50)->nullable();
            $table->string('supplier_name', 100);
            $table->string('check_writer_name', 100)->nullable();

            // Contact
            $table->string('mobile', 20)->nullable();
            $table->string('land_line', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('fax', 20)->nullable();
            $table->string('website', 255)->nullable();

            // Billing address
            $table->string('bil_address_line_1', 100)->nullable();
            $table->string('bil_address_line_2', 100)->nullable();
            $table->string('bil_address_line_3', 100)->nullable();
            $table->string('bil_city', 50)->nullable();
            $table->string('bil_postal_code', 20)->nullable();
            $table->string('bil_country', 50)->nullable();
            $table->string('bil_state_province', 50)->nullable();

            // Tax
            $table->string('tax_type', 50)->nullable();
            $table->string('tax_no', 50)->nullable();
            $table->string('tax_regis_no', 50)->nullable();

            // Financial terms
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->unsignedSmallInteger('credit_period')->nullable();
            $table->decimal('privileges_discount', 5, 2)->nullable();

            // Banking
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('bank_acc_holder_name', 100)->nullable();
            $table->string('bank_acc_no', 50)->nullable();

            // Contact person
            $table->string('contact_person_name', 100)->nullable();
            $table->string('contact_person_designation', 100)->nullable();
            $table->string('contact_person_mobile', 20)->nullable();
            $table->string('contact_person_email', 100)->nullable();
            $table->string('contact_person_fax', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_masters');
    }
};
