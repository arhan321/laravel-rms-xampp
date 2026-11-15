<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_steel_dust_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()->constrained('maintenance_plans')->noActionOnDelete();
            $table->uuid('submission_id');
            $table->date('inspected_on');
            $table->string('unit', 24);
            $table->json('readings');
            $table->string('inspected_by', 120);
            $table->string('limit_reference', 250)->nullable();
            $table->string('limit_approved_by', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz(3);

            $table->unique(['robot_id', 'submission_id'], 'steel_dust_robot_submission_unique');
            $table->index(['robot_id', 'inspected_on', 'id'], 'steel_dust_robot_history_idx');
            $table->index('maintenance_plan_id', 'steel_dust_plan_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_steel_dust_inspections');
    }
};
