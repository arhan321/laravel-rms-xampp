<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Existing databases may already contain job_name from earlier imports.
        // Never delete, rename, or overwrite existing job metadata.
        if (! Schema::hasColumn('robot_telemetry_samples', 'job_name')) {
            Schema::table('robot_telemetry_samples', function (Blueprint $table): void {
                $table->string('job_name', 120)->nullable();
                $table->index(['robot_id', 'job_name', 'sampled_at'], 'rms_job_torque_robot_job_time_idx');
            });
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive: job history must not be dropped on rollback.
        // To remove schema manually, use an approved database maintenance process.
    }
};
