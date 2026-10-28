<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a dropped column's values go, so a schema clean-up never loses client
 * data.
 *
 * A migration that removes a column first copies every non-empty value here
 * (table, column, row id, value), and its down() puts them back. Nothing reads
 * this table at runtime; it is the audit copy and the rollback source.
 *
 * Sorts before the supplier_type drops (…000008 / …000009), which use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_archived_column_values')) {
            return;
        }

        Schema::create('core_archived_column_values', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name', 64);
            $table->string('column_name', 64);
            $table->unsignedBigInteger('record_id');
            $table->text('value');
            $table->timestamp('archived_at')->useCurrent();

            $table->unique(['table_name', 'column_name', 'record_id'], 'core_archived_column_values_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_archived_column_values');
    }
};
