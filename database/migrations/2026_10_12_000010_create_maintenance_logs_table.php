<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained('maintenance_plans')->noActionOnDelete();
            $table->string('action_type', 40)->default('preventive');
            $table->string('result', 40)->default('recorded'); // recorded, completed, follow_up_required
            $table->string('performed_by', 120);
            $table->timestampTz('started_at', 3)->nullable();
            $table->timestampTz('completed_at', 3)->nullable();
            $table->text('findings')->nullable();
            $table->text('action_taken')->nullable();
            $table->jsonb('supporting_data')->nullable();
            $table->string('verified_by', 120)->nullable();
            $table->timestampTz('verified_at', 3)->nullable();
            $table->timestampsTz(3);

            $table->index(['robot_id', 'completed_at'], 'pm_log_robot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_logs');
    }
};

