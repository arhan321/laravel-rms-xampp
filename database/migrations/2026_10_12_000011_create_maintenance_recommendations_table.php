<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('robot_health_assessment_id')->nullable()
                ->constrained('robot_health_assessments')->noActionOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->noActionOnDelete();
            $table->string('source', 40)->default('rule_engine');
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('open');
            // open, acknowledged, resolved, dismissed
            $table->string('title', 180);
            $table->text('recommendation_text');
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('generated_at', 3);
            $table->timestampTz('acknowledged_at', 3)->nullable();
            $table->string('acknowledged_by', 120)->nullable();
            $table->timestampTz('closed_at', 3)->nullable();
            $table->text('closure_note')->nullable();
            $table->timestampsTz(3);

            $table->index(['robot_id', 'status', 'generated_at'], 'pm_rec_robot_status_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_recommendations');
    }
};

