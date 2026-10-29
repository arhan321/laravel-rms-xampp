<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('robot_threshold_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->foreignId('threshold_rule_id')->constrained('robot_threshold_rules')->noActionOnDelete();
            $table->foreignId('trigger_sample_id')->nullable()->constrained('robot_telemetry_samples')->nullOnDelete();
            $table->string('axis', 2);
            $table->string('severity', 16); // warning / critical
            $table->string('status', 16)->default('open'); // open / closed
            $table->decimal('peak_magnitude', 18, 6);
            $table->timestampTz('started_at', 3);
            $table->timestampTz('last_seen_at', 3);
            $table->timestampTz('closed_at', 3)->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz(3);
            $table->index(['robot_id','started_at'], 'rms_threshold_event_robot_time_idx');
            $table->index(['threshold_rule_id','status']);
        });
        DB::statement("CREATE UNIQUE INDEX rms_threshold_only_one_open_event_idx ON robot_threshold_events (threshold_rule_id) WHERE status = 'open'");
    }
    public function down(): void { Schema::dropIfExists('robot_threshold_events'); }
};

