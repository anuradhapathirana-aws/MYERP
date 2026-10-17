<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proforma invoice — a priced, non-binding offer raised from a confirmed sales
 * order before delivery. No stock, receivable or ledger effect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_proforma_invoices', function (Blueprint $table): void {
            $table->id();

            $table->string('proforma_no', 60)->unique();

            // Extendable soft source link — 'sales_order' today; a delivery order,
            // quotation or standalone proforma can be added without a schema change.
            $table->string('source_type', 30)->default('sales_order');
            $table->unsignedBigInteger('source_id')->nullable();

            // Soft links (no FKs) — so_id kept for fast filtering / invoice auto-linking.
            // One live (non-cancelled) proforma per SO is enforced in the service.
            $table->unsignedBigInteger('so_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('company_id')->nullable();

            $table->date('proforma_date');
            $table->date('valid_until')->nullable();
            $table->text('payment_terms')->nullable();
            $table->string('invoice_type', 10)->default('non_tax');

            // draft | issued | partially_invoiced | converted | cancelled
            $table->string('status', 30)->default('draft');

            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('transport_charge', 15, 4)->default(0);
            $table->decimal('grand_total', 15, 4)->default(0);

            $table->text('delivery_address')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['source_type', 'source_id']);
            $table->index('so_id');
            $table->index('customer_id');
            $table->index('status');
            $table->index('proforma_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_proforma_invoices');
    }
};
