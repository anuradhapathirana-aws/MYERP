<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to any Finance document (bill, later payment, voucher ...).
 *
 * One table and one service for every document, so the upload rules -- type
 * by content, size, private disk, permission-checked download -- are written
 * and tested once.
 *
 * attachable_type is the short alias ('bill'), not a class name. stored_path
 * is never sent to the client; downloads go through the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_attachments', function (Blueprint $table): void {
            $table->id();

            $table->string('attachable_type', 40);
            $table->unsignedBigInteger('attachable_id');

            $table->string('original_name', 255);
            $table->string('stored_path', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');

            $table->unsignedBigInteger('uploaded_by')->nullable();

            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'fin_attach_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_attachments');
    }
};
