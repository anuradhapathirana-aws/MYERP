<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_goods_received_note_items', function (Blueprint $table): void {
            $table->unsignedInteger('no_of_pieces')->default(0)->after('quantity_received');
        });
    }

    public function down(): void
    {
        Schema::table('inv_goods_received_note_items', function (Blueprint $table): void {
            $table->dropColumn('no_of_pieces');
        });
    }
};
