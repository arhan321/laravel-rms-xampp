<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_alarm_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('source_kind', 12);
            $table->unsignedBigInteger('source_record');
            $table->string('alarm_code', 60);
            $table->string('subcode', 60)->nullable();
            $table->timestampTz('alarm_observed_at', 3);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('evidence');
            $table->json('work')->nullable();
            $table->json('signatures')->nullable();
            $table->timestampsTz(3);
            $table->unique(['source_kind', 'source_record'], 'alarm_fui_source_unique');
            $table->index(['robot_id', 'alarm_code', 'subcode', 'status', 'alarm_observed_at'], 'alarm_fui_robot_code_idx');
        });

        Schema::create('robot_alarm_follow_up_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_id')->constrained('robot_alarm_follow_ups')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 24);
            $table->string('recorded_by', 120)->nullable();
            $table->json('payload');
            $table->timestampsTz(3);
            $table->unique(['follow_up_id', 'version'], 'alarm_fui_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_alarm_follow_up_revisions');
        Schema::dropIfExists('robot_alarm_follow_ups');
    }
};
