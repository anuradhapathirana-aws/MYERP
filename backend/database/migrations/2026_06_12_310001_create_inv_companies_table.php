<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('company_type', 50)->nullable();
            $table->string('company_name', 100);
            $table->string('registration_no', 50)->nullable()->unique();
            $table->string('tax_reg_no', 50)->nullable();
            $table->string('street_address', 100)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('country', 50)->nullable();
            $table->string('state', 50)->nullable();
            $table->string('postal_zip_code', 20)->nullable();
            $table->string('company_email', 100)->nullable();
            $table->string('company_mobile', 20)->nullable();
            $table->foreignId('industry_id')
                ->nullable()
                ->constrained('inv_industries')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_companies');
    }
};
