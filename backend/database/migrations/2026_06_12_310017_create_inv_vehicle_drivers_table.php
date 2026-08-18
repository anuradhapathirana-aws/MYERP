<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_vehicle_drivers', function (Blueprint $table): void {
            // Soft links — no hard FK constraints (architecture golden rule)
            $table->unsignedBigInteger('vehicle_master_id');
            $table->unsignedBigInteger('driver_id');
            $table->primary(['vehicle_master_id', 'driver_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_vehicle_drivers');
    }
};
