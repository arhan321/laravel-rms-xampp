<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('controller_id')->constrained('robot_controllers')->noActionOnDelete();
            $table->string('robot_code', 80)->unique();
            $table->string('name', 150);
            $table->string('model', 80)->default('SP210');
            $table->string('control_group', 8)->default('R1');
            $table->unsignedTinyInteger('axis_count')->default(6);
            $table->string('line_name', 100)->nullable();
            $table->string('station_name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz(3);

            $table->unique(['controller_id', 'control_group'], 'robots_controller_group_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robots');
    }
};

