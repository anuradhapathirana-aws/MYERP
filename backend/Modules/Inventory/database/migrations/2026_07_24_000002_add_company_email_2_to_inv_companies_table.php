<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_companies', function (Blueprint $table): void {
            $table->string('company_email_2', 100)->nullable()->after('company_email');
        });
    }

    public function down(): void
    {
        Schema::table('inv_companies', function (Blueprint $table): void {
            $table->dropColumn('company_email_2');
        });
    }
};
