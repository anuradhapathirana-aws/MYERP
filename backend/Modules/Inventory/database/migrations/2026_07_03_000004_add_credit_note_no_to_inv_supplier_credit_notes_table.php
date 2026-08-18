<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_supplier_credit_notes', function (Blueprint $table): void {
            $table->string('credit_note_no', 30)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_supplier_credit_notes', function (Blueprint $table): void {
            $table->dropColumn('credit_note_no');
        });
    }
};
