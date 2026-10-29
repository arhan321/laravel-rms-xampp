<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matches the latest-sample query used by Cockpit and the telemetry API.
        Schema::table('robot_telemetry_samples', function (Blueprint $table): void {
            $table->index(['robot_id', 'received_at', 'id'], 'telemetry_robot_received_idx');
        });
    }

    public function down(): void
    {
        Schema::table('robot_telemetry_samples', function (Blueprint $table): void {
            $table->dropIndex('telemetry_robot_received_idx');
        });
    }
};
