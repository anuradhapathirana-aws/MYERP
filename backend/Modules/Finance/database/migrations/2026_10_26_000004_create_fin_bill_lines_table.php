<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expense lines of a bill.
 *
 * The screen shows one account and one amount today and saves exactly one
 * line. Keeping lines in their own table is what lets a later release split a
 * bill across accounts, or add tax and cost centres per line, without a data
 * migration.
 *
 * Both FKs stay inside Finance, so they are hard keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bill_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bill_id')->constrained('fin_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');

            $table->foreignId('ledger_account_id')->constrained('fin_ledger_accounts')->restrictOnDelete();

            $table->string('description', 500)->nullable();
            $table->decimal('amount', 15, 2);

            // Reserved for the Tax Master.
            $table->unsignedBigInteger('tax_code_id')->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();

            $table->timestamps();

            $table->unique(['bill_id', 'line_no'], 'fin_bill_lines_no_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bill_lines');
    }
};
