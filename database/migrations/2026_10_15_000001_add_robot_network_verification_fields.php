<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_controllers', function (Blueprint $table): void {
            $table->timestampTz('network_verified_at', 3)->nullable();
            $table->string('network_verification_status', 32)->default('never');
            $table->string('network_verification_method', 32)->nullable();
            $table->text('network_verification_message')->nullable();
            $table->decimal('network_verification_latency_ms', 10, 1)->nullable();
            $table->unique('ip_address', 'robot_controllers_ip_unique');
        });
    }

    public function down(): void
    {
        Schema::table('robot_controllers', function (Blueprint $table): void {
            $table->dropUnique('robot_controllers_ip_unique');
            $table->dropColumn([
                'network_verified_at',
                'network_verification_status',
                'network_verification_method',
                'network_verification_message',
                'network_verification_latency_ms',
            ]);
        });
    }
};
