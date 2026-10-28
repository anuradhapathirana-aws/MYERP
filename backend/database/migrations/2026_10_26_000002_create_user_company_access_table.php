<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which companies a user may work with.
 *
 * NO ROWS means ALL companies. That is what makes this safe to ship: every
 * existing user has no rows, so nobody loses access on the day it deploys, and
 * a single-company client never has to touch it.
 *
 * company_id is a soft link (no FK), the same as fin_ledger_accounts.company_id:
 * inv_companies carries no SoftDeletes and the module rule forbids a hard key
 * into another module's table. user_id does carry one, because users is core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_company_access', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id')->index();

            $table->timestamps();

            $table->unique(['user_id', 'company_id'], 'user_company_access_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company_access');
    }
};
