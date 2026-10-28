<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The change history every controlled document writes to.
 *
 * In core rather than Finance because core is always installed: a user's
 * company access is audited on an Inventory-only client too, and any module can
 * adopt the Auditable trait without depending on another module.
 *
 * Insert-only. There is no updated_at and the model refuses update and delete,
 * because a history that can be edited proves nothing.
 *
 * auditable_type is the short alias ('bill', 'user'), never a class name, so a
 * namespace refactor cannot orphan the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('auditable_type', 40);
            $table->unsignedBigInteger('auditable_id');

            $table->string('event', 40);

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'core_audit_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_audit_logs');
    }
};
