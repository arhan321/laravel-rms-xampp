<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_operating_time_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('time_type', 80); // e.g. ServoPowerOnTimeRobot
            $table->timestampTz('sampled_at', 3);
            $table->timestampTz('received_at', 3)->useCurrent();
            $table->boolean('read_ok')->default(false);
            $table->integer('status_code')->nullable();
            $table->text('status_message')->nullable();
            $table->text('value_raw')->nullable(); // SDK elapsedTime, don't assume seconds.
            $table->unsignedBigInteger('value_seconds')->nullable(); // Only when unit is verified.
            $table->jsonb('raw_payload')->nullable();
            $table->timestampsTz(3);

            $table->unique(['robot_id', 'time_type', 'sampled_at'], 'operating_robot_type_time_uq');
            $table->index(['robot_id', 'sampled_at'], 'operating_robot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_operating_time_samples');
    }
};

