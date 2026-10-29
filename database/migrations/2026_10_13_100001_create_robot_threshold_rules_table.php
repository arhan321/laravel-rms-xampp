<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('robot_threshold_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('axis', 2); // S L U R B T
            $table->string('metric', 48)->default('torque_magnitude');
            $table->string('unit', 32)->default('sdk_raw');
            $table->decimal('warning_magnitude', 18, 6);
            $table->decimal('critical_magnitude', 18, 6);
            $table->decimal('clear_hysteresis_percent', 5, 2)->default(5);
            $table->boolean('enabled')->default(false);
            $table->boolean('unit_verified')->default(false);
            $table->string('source_reference', 250)->nullable();
            $table->string('approved_by', 120)->nullable();
            $table->timestampTz('approved_at', 3)->nullable();
            $table->foreignId('last_evaluated_sample_id')->nullable()
                ->constrained('robot_telemetry_samples')->nullOnDelete();
            $table->timestampsTz(3);
            $table->unique(['robot_id','axis','metric'], 'rms_threshold_robot_axis_metric_uq');
            $table->index(['robot_id','enabled']);
        });
        // PostgreSQL-level invariants: cannot configure negative or inverted thresholds.
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE robot_threshold_rules ADD CONSTRAINT rms_threshold_limits_ck CHECK (warning_magnitude > 0 AND critical_magnitude > warning_magnitude AND clear_hysteresis_percent >= 0 AND clear_hysteresis_percent <= 30)");
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE robot_threshold_rules ADD CONSTRAINT rms_threshold_axis_ck CHECK (axis IN ('S','L','U','R','B','T'))");
    }
    public function down(): void { Schema::dropIfExists('robot_threshold_rules'); }
};

