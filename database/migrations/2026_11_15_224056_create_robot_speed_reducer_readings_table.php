<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_speed_reducer_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained()->cascadeOnDelete();
            $table->string('control_group', 3);
            $table->date('date_wib');
            $table->string('axis', 2);
            $table->decimal('measurement_pct', 14, 6)->nullable();
            $table->decimal('variation_pp', 14, 6)->nullable();
            $table->decimal('operating_hours', 14, 6)->nullable();
            $table->decimal('remaining_life_hours', 14, 6)->nullable();
            $table->decimal('variation_lower_pp', 14, 6)->nullable();
            $table->decimal('variation_upper_pp', 14, 6)->nullable();
            $table->decimal('life_lower_hours', 14, 6)->nullable();
            $table->decimal('life_upper_hours', 14, 6)->nullable();
            $table->string('source_reference', 255);
            $table->string('verified_by', 120);
            $table->timestamps();

            $table->unique(['robot_id', 'control_group', 'date_wib', 'axis'], 'rms_speed_reducer_daily_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_speed_reducer_readings');
    }
};
