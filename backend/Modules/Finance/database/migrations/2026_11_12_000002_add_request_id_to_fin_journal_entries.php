<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency for journals posted straight from a screen with no document of
 * their own -- today the bank charges / interest entry on Bank Reconciliation.
 * A double-clicked or retried post sends the same request_id and gets the
 * first journal back instead of a second one. Null for everything else, and
 * MySQL allows any number of NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_journal_entries', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->unique()->after('journal_no');
        });
    }

    public function down(): void
    {
        Schema::table('fin_journal_entries', function (Blueprint $table): void {
            $table->dropUnique(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
