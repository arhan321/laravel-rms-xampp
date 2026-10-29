<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('basis', 32)->default('operating_hours'); // operating_hours / calendar
            $table->decimal('interval_hours', 12, 2)->nullable();
            $table->unsignedInteger('interval_days')->nullable();
            $table->decimal('next_due_operating_hours', 14, 2)->nullable();
            $table->timestampTz('next_due_at', 3)->nullable();
            $table->timestampTz('last_completed_at', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 120)->nullable();
            $table->timestampsTz(3);

            $table->index(['robot_id', 'is_active'], 'pm_plan_robot_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_plans');
    }
};

