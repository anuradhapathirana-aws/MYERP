<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_grn_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grn_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();

            $table->index('grn_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_grn_attachments');
    }
};
