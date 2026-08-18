<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_delivery_orders', function (Blueprint $table): void {
            // "Date" on the dispatch note — the DO document date (distinct from the ship date).
            $table->date('document_date')->nullable()->after('do_no');

            // Free-text dispatch details (no master lookups for now).
            $table->string('delivery_mode', 40)->nullable()->after('delivery_date');
            $table->string('delivery_vehicle', 150)->nullable()->after('delivery_mode');
            $table->string('responsible_person', 150)->nullable()->after('delivery_vehicle');
        });
    }

    public function down(): void
    {
        Schema::table('inv_delivery_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'document_date',
                'delivery_mode',
                'delivery_vehicle',
                'responsible_person',
            ]);
        });
    }
};
