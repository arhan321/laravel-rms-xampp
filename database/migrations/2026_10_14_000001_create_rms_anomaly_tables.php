<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('robot_telemetry_samples', static function (Blueprint $table): void {
            $table->string('job_name', 120)->nullable(); // populated ONLY by trustworthy collector
            $table->index(['robot_id', 'job_name', 'sampled_at'], 'rms_telemetry_job_idx');
        });
        Schema::create('robot_anomaly_baselines', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('axis', 2);
            $table->string('job_key', 120)->default('__unspecified__');
            $table->string('metric', 40)->default('torque_abs');
            $table->string('unit', 40)->default('sdk_raw');
            $table->decimal('median_value', 20, 6);
            $table->decimal('mad_value', 20, 6);
            $table->unsignedInteger('sample_count');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->string('version', 80);
            $table->timestampsTz();
            $table->unique(['robot_id','axis','job_key','metric'], 'rms_baseline_scope_uq');
        });
        Schema::create('robot_anomaly_events', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('baseline_id')->constrained('robot_anomaly_baselines')->noActionOnDelete();
            $table->foreignId('trigger_sample_id')->constrained('robot_telemetry_samples')->noActionOnDelete();
            $table->string('axis', 2);
            $table->string('job_key', 120);
            $table->string('severity', 16);
            $table->string('status', 20)->default('open'); // open / acknowledged / reviewed / cleared
            $table->decimal('score', 8, 2);
            $table->decimal('robust_z', 12, 4);
            $table->decimal('observed_value', 20, 6);
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('last_evaluated_at')->nullable();
            $table->foreignId('last_evaluated_sample_id')->nullable()->constrained('robot_telemetry_samples')->nullOnDelete();
            $table->unsignedSmallInteger('recovery_samples')->default(0);
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->string('acknowledged_by', 150)->nullable();
            $table->string('feedback', 30)->nullable(); // confirmed / false_positive / inconclusive
            $table->text('technician_note')->nullable();
            $table->foreignId('maintenance_log_id')->nullable()->constrained('maintenance_logs')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('reviewed_by', 150)->nullable();
            $table->timestampsTz();
            $table->index(['robot_id','status','started_at'], 'rms_anomaly_robot_status_idx');
        });
        DB::statement("CREATE UNIQUE INDEX rms_one_open_anomaly_per_scope ON robot_anomaly_events (robot_id, axis, job_key) WHERE status IN ('open','acknowledged')");
    }
    public function down(): void
    {
        Schema::dropIfExists('robot_anomaly_events');
        Schema::dropIfExists('robot_anomaly_baselines');
        Schema::table('robot_telemetry_samples', static function (Blueprint $table): void {
            $table->dropIndex('rms_telemetry_job_idx');
            $table->dropColumn('job_name');
        });
    }
};

