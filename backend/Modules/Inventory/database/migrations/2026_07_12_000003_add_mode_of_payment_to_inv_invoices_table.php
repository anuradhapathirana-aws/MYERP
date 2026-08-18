<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->string('mode_of_payment', 30)->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->dropColumn('mode_of_payment');
        });
    }
};
