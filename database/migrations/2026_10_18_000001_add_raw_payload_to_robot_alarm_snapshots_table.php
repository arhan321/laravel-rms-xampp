<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robot_alarm_snapshots', function (Blueprint $table): void {
            $table->jsonb('raw_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('robot_alarm_snapshots', function (Blueprint $table): void {
            $table->dropColumn('raw_payload');
        });
    }
};
