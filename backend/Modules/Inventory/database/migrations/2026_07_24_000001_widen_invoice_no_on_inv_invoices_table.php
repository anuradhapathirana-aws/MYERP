<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_no moves to the IRD serial format YYMMM_CustomerCode_Serial
 * (e.g. 26JUL_CUS-0001_142) — wider than the old INV-YYYY-NNNN scheme,
 * so the column needs more room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->string('invoice_no', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->string('invoice_no', 30)->change();
        });
    }
};
