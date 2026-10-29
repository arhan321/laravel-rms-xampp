<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additional optional PM documentation fields. Never drops the old plans. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table): void {
            $table->string('component_axis', 5)->nullable();
            $table->string('grease_specification', 250)->nullable();
            $table->string('reference_document', 250)->nullable();
            $table->text('work_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'component_axis', 'grease_specification',
                'reference_document', 'work_instructions',
            ]);
        });
    }
};
