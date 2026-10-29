<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('robot_prediction_models', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('axis', 2);
            $table->unsignedInteger('version');
            $table->string('algorithm', 80)->default('linear_trend_verified_inspections');
            $table->string('status', 30)->default('experimental');
            $table->string('condition_unit', 50);
            $table->decimal('threshold_value', 20, 6);
            $table->jsonb('parameters'); // slope units/day, intercept, starting timestamp
            $table->unsignedInteger('train_count');
            $table->unsignedInteger('validation_count');
            $table->decimal('validation_mae', 20, 6)->nullable();
            $table->decimal('validation_rmse', 20, 6)->nullable();
            $table->timestampTz('trained_at');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');
            $table->string('source_fingerprint', 64);
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
            $table->unique(['robot_id','axis','version'], 'rms_prediction_version_uq');
            $table->index(['robot_id','axis','is_current'], 'rms_prediction_current_idx');
        });
        Schema::create('robot_predictions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('model_id')->constrained('robot_prediction_models')->noActionOnDelete();
            $table->foreignId('source_measurement_id')->constrained('robot_reducer_measurements')->noActionOnDelete();
            $table->string('axis', 2);
            $table->string('status', 32); // projected / not_projectable
            $table->timestampTz('predicted_threshold_at')->nullable();
            $table->decimal('remaining_calendar_days', 12, 2)->nullable();
            $table->decimal('observed_value', 20, 6);
            $table->text('reason')->nullable();
            $table->timestampTz('generated_at');
            $table->string('technician_verdict', 32)->nullable();
            $table->foreignId('maintenance_log_id')->nullable()->constrained('maintenance_logs')->nullOnDelete();
            $table->text('feedback_note')->nullable();
            $table->timestampTz('feedback_at')->nullable();
            $table->string('feedback_by', 150)->nullable();
            $table->timestampsTz();
            $table->unique(['model_id','source_measurement_id'], 'rms_prediction_source_uq');
            $table->index(['robot_id','generated_at'], 'rms_predictions_robot_time_idx');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('robot_predictions');
        Schema::dropIfExists('robot_prediction_models');
    }
};

