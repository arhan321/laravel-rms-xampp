<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_controller_state_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('telemetry_sample_id')->unique()->constrained('robot_telemetry_samples')->cascadeOnDelete();
            $table->timestampTz('sampled_at', 3);
            $table->timestampTz('received_at', 3);
            $table->boolean('running');
            $table->boolean('servo_on');
            $table->boolean('alarm');
            $table->boolean('hold');
            $table->boolean('error');
            $table->string('control_mode', 16)->nullable();
            $table->string('cycle_mode', 16)->nullable();
            $table->timestampsTz(3);
            $table->index(['robot_id', 'sampled_at', 'id'], 'state_robot_sample_idx');
        });

        Schema::create('robot_alarm_history_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('first_snapshot_id')->constrained('robot_alarm_snapshots')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('category', 32);
            $table->string('alarm_code', 60);
            $table->string('subcode', 60)->nullable();
            $table->string('alarm_name', 255);
            // The controller clock may be wrong or lack a date. Keep its text as evidence.
            $table->string('controller_time_raw', 120)->nullable();
            $table->timestampTz('first_observed_at', 3);
            $table->timestampTz('last_observed_at', 3);
            $table->timestampsTz(3);
            $table->unique(['robot_id', 'fingerprint'], 'alarm_history_robot_fingerprint_uq');
            $table->index(['robot_id', 'first_observed_at'], 'alarm_history_robot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_alarm_history_entries');
        Schema::dropIfExists('robot_controller_state_samples');
    }
};

