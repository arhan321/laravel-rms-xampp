<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('robot_reducer_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('axis', 2);
            $table->timestampTz('measured_at', 3);
            $table->decimal('condition_value', 18, 6)->nullable();
            $table->string('condition_unit', 50)->nullable();
            $table->decimal('remaining_life_hours', 14, 2)->nullable();
            $table->decimal('lower_threshold', 18, 6)->nullable();
            $table->decimal('upper_threshold', 18, 6)->nullable();
            $table->string('source_type', 50); // oem_cockpit, oem_report, technician_measurement
            $table->string('source_reference', 255);
            $table->boolean('is_verified')->default(false);
            $table->string('verified_by', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz(3);
            $table->index(['robot_id','axis','measured_at'], 'rms_reducer_robot_axis_time_idx');
        });
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE robot_reducer_measurements ADD CONSTRAINT rms_reducer_axis_ck CHECK (axis IN ('S','L','U','R','B','T'))");
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE robot_reducer_measurements ADD CONSTRAINT rms_reducer_life_ck CHECK (remaining_life_hours IS NULL OR remaining_life_hours >= 0)");
    }
    public function down(): void { Schema::dropIfExists('robot_reducer_measurements'); }
};

