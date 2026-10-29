<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('robot_telemetry_samples', function (Blueprint $table): void {
            // Nullable additions preserve all existing telemetry. No data is rebuilt here.
            // Use JSON (NVARCHAR(MAX) on SQL Server), not PostgreSQL-only JSON operators.
            $table->json('servo_current_estimate')->nullable();
            foreach (['s', 'l', 'u', 'r', 'b', 't'] as $axis) {
                $table->double('servo_current_'.$axis.'_arms')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robot_telemetry_samples', function (Blueprint $table): void {
            $table->dropColumn([
                'servo_current_estimate',
                'servo_current_s_arms', 'servo_current_l_arms', 'servo_current_u_arms',
                'servo_current_r_arms', 'servo_current_b_arms', 'servo_current_t_arms',
            ]);
        });
    }
};
