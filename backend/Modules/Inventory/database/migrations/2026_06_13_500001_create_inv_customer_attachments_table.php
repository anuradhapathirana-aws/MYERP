<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_master_id');
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamps();

            $table->index('customer_master_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_attachments');
    }
};
