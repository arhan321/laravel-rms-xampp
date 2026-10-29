<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table): void {
            $table->string('component_category', 40)->nullable();
            $table->index(['robot_id', 'component_category'], 'pm_robot_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table): void {
            $table->dropIndex('pm_robot_category_idx');
            $table->dropColumn('component_category');
        });
    }
};
