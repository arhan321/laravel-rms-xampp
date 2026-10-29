<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_logs', function (Blueprint $table): void {
            $table->foreignId('maintenance_recommendation_id')
                ->nullable()
                ->constrained('maintenance_recommendations')
                ->noActionOnDelete();
            $table->index('maintenance_recommendation_id', 'pm_log_recommendation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_logs', function (Blueprint $table): void {
            $table->dropIndex('pm_log_recommendation_idx');
            $table->dropConstrainedForeignId('maintenance_recommendation_id');
        });
    }
};

