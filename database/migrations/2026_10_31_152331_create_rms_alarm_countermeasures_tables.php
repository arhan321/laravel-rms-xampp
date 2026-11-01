<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rms_alarm_countermeasures', function (Blueprint $table): void {
            $table->id();
            $table->string('controller_model', 80);
            $table->string('controller_model_key', 80);
            $table->string('alarm_code', 60);
            $table->string('subcode', 60)->nullable();
            $table->boolean('all_subcodes')->default(false);
            // SET NULL on robot deletion would incorrectly broaden a robot-specific guide.
            $table->unsignedBigInteger('scope_robot_id')->nullable();
            $table->string('firmware_version', 80)->nullable();
            $table->string('source_type', 24);
            $table->string('title', 180);
            $table->text('alarm_content')->nullable();
            $table->text('cause')->nullable();
            $table->text('measure');
            $table->string('source_reference', 500)->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->string('source_revision', 120)->nullable();
            $table->string('reported_by', 120);
            $table->string('work_area', 120)->nullable();
            $table->string('result', 24)->default('not_tested');
            $table->text('result_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->json('origin');
            $table->timestampsTz(3);
            $table->index(['controller_model_key', 'alarm_code', 'subcode'], 'rms_alarm_cm_match_idx');
            $table->index('scope_robot_id', 'rms_alarm_cm_robot_idx');
        });
        Schema::create('rms_alarm_countermeasure_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('countermeasure_id')->constrained('rms_alarm_countermeasures')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('edited_by', 120);
            $table->json('payload');
            $table->timestampTz('recorded_at', 3);
            $table->unique(['countermeasure_id', 'version'], 'rms_alarm_cm_revision_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rms_alarm_countermeasure_revisions');
        Schema::dropIfExists('rms_alarm_countermeasures');
    }
};
