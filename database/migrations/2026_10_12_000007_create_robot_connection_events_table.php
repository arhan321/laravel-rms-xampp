<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_connection_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('robot_id')->constrained('robots')->noActionOnDelete();
            $table->string('state', 32); // connected, offline, reconnecting, stopped
            $table->text('message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('occurred_at', 3);
            $table->jsonb('raw_payload')->nullable();
            $table->timestampsTz(3);

            $table->index(['robot_id', 'occurred_at'], 'connection_robot_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robot_connection_events');
    }
};

