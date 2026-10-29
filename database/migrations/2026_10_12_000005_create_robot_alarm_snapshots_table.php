<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // YMConnect GetActiveAlarms is currently serialized to raw SDK text.
        // A read failure must NOT be treated as "no active alarms".
        Schema::create('robot_alarm_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->timestampTz('sampled_at', 3);
            $table->timestampTz('received_at', 3)->useCurrent();
            $table->boolean('read_ok')->default(false);
            $table->integer('status_code')->nullable();
            $table->text('status_message')->nullable();
            $table->text('sdk_text')->nullable();
            $table->unsignedInteger('active_alarm_count')->nullable(); // Only after proper parsing.
            $table->jsonb('parsed_alarms')->nullable(); // Future structured decoding.
            $table->timestampsTz(3);

            $table->unique(['robot_id', 'sampled_at'], 'alarm_snap_robot_time_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_alarm_snapshots');
    }
};

