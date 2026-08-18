<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_purchase_requests', function (Blueprint $table): void {
            $table->id();

            // Auto-generated document number (e.g. PR-2026-0001)
            $table->string('pr_no', 30)->unique();

            // Auto-generated reference number — unique, immutable after assignment
            $table->string('reference_no', 50)->unique();

            // Header dates
            $table->date('request_date');
            $table->date('required_date')->nullable();

            // Purpose / description of the request
            $table->string('purpose', 200)->nullable();

            // Source (requesting) location & store — soft links
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->unsignedBigInteger('source_store_id')->nullable();

            // Target (destination) warehouse — soft links
            $table->unsignedBigInteger('target_location_id')->nullable();
            $table->unsignedBigInteger('target_store_id')->nullable();

            // Customer soft link — nullable, no hard FK (architecture rule)
            $table->unsignedBigInteger('customer_id')->nullable();

            // Logistics
            $table->string('transport_mode', 100)->nullable();

            // Remarks / notes
            $table->text('remarks')->nullable();

            // Workflow status
            $table->string('status', 30)->default('draft');
            // Values: draft | submitted | approved | rejected | cancelled | completed

            // Audit — soft links to users table
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for common filter queries
            $table->index('status');
            $table->index('request_date');
            $table->index('source_store_id');
            $table->index('target_store_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_purchase_requests');
    }
};
