<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a deposit carried to the bank: at most one cash line, and one line per
 * cheque.
 *
 * from_account_id is the Cash Book the money leaves (a Finance table, real key).
 *
 * A cheque line points at the received cheque through source_type/source_id,
 * a SOFT link to inv_customer_receipt_settlements: that table belongs to
 * Inventory, and the Golden Rule forbids a hard key into it. The cheque's
 * details are snapshotted so the slip still prints if the receipt is later
 * edited or Inventory is switched off.
 *
 * One cheque may sit on only one live (draft or confirmed, not deleted)
 * deposit. That cannot be a unique index -- voided and deleted deposits keep
 * their lines -- so BankDepositService enforces it under a row lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_deposit_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_deposit_id')->constrained('fin_bank_deposits')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');

            // cash | cheque
            $table->string('line_type', 10);
            $table->foreignId('from_account_id')->constrained('fin_ledger_accounts');

            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Cheque snapshot (null on the cash line).
            $table->string('receipt_no', 30)->nullable();
            $table->date('receipt_date')->nullable();
            $table->string('customer_name', 150)->nullable();
            $table->string('cheque_no', 30)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('drawee_bank', 100)->nullable();

            $table->decimal('amount', 15, 2);

            $table->timestamps();

            $table->unique(['bank_deposit_id', 'line_no'], 'fin_bank_deposit_lines_no_unique');
            $table->index(['source_type', 'source_id'], 'fin_bank_deposit_lines_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_deposit_lines');
    }
};
