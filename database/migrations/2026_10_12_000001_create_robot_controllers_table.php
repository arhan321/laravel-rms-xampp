<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_controllers', function (Blueprint $table): void {
            $table->id();
            $table->string('controller_code', 80)->unique();
            $table->string('model', 80)->default('YRC1000');
            $table->string('ip_address', 45);
            $table->string('firmware_version', 80)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('connection_status', 32)->default('unknown');
            $table->timestampTz('last_seen_at', 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_controllers');
    }
};
