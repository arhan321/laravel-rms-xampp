<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One row per fast polling cycle, NOT one row per axis.
        // Do not interpret SDK RAW torque numbers as Nm or percent without validation.
        Schema::create('robot_telemetry_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->uuid('collector_session_id')->nullable(); // Optional, for reliable replay deduplication.
            $table->unsignedBigInteger('sequence')->nullable();
            $table->unsignedBigInteger('collector_uptime_seconds')->nullable();
            $table->timestampTz('sampled_at', 3);
            $table->timestampTz('received_at', 3)->useCurrent();

            $table->boolean('position_ok')->default(false);
            $table->integer('position_status_code')->nullable();
            $table->text('position_status_message')->nullable();
            $table->timestampTz('position_sampled_at', 3)->nullable();
            $table->jsonb('position_pulses')->nullable(); // {S,L,U,R,B,T}

            $table->boolean('torque_ok')->default(false);
            $table->integer('torque_status_code')->nullable();
            $table->text('torque_status_message')->nullable();
            $table->timestampTz('torque_sampled_at', 3)->nullable();
            $table->string('torque_unit', 32)->default('sdk_raw');
            $table->jsonb('torque_axes')->nullable(); // {S,L,U,R,B,T}

            $table->jsonb('raw_payload')->nullable(); // Optional troubleshooting; disable for high-volume storage.
            $table->timestampsTz(3);

            $table->index(['robot_id', 'sampled_at'], 'telemetry_robot_time_idx');
            if (DB::connection()->getDriverName() !== 'sqlsrv') {
                $table->unique(['robot_id', 'collector_session_id', 'sequence'], 'telemetry_session_seq_uq');
            }
        });
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            DB::statement('CREATE UNIQUE INDEX [telemetry_session_seq_uq] ON [robot_telemetry_samples] ([robot_id], [collector_session_id], [sequence]) WHERE [collector_session_id] IS NOT NULL AND [sequence] IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_telemetry_samples');
    }
};

