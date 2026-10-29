<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Health is computed by validated rules in a later phase, never by database defaults.
        Schema::create('robot_health_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('health_status', 32)->default('unknown');
            // unknown, healthy, warning, critical, offline, insufficient_data
            $table->timestampTz('evaluated_at', 3);
            $table->string('rule_version', 80)->nullable();
            $table->string('assessment_source', 50)->default('rule_engine');
            $table->text('summary')->nullable();
            $table->jsonb('indicators')->nullable();
            $table->timestampsTz(3);

            $table->index(['robot_id', 'evaluated_at'], 'health_robot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_health_assessments');
    }
};

