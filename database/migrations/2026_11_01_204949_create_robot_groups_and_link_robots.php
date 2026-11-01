<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robot_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 80)->unique();
            $table->string('name', 100);
            $table->char('name_key', 64)->unique();
            $table->string('area_label', 150)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('planned_robots')->nullable();
            $table->boolean('is_pilot')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz(3);
        });
        Schema::table('robots', function (Blueprint $table): void {
            $table->foreignId('robot_group_id')->nullable()->constrained('robot_groups')->noActionOnDelete();
            $table->index('robot_group_id', 'robots_group_idx');
        });

        /** The pretend command reports DDL without attempting a data import. */
        if (! DB::connection()->pretending()) {
            $this->importExistingGroups();
        }
    }

    public function down(): void
    {
        Schema::table('robots', function (Blueprint $table): void {
            $table->dropForeign(['robot_group_id']);
            $table->dropIndex('robots_group_idx');
            $table->dropColumn('robot_group_id');
        });
        Schema::dropIfExists('robot_groups');
    }

    /** Import the previous config and line labels once; runtime reads only database records. */
    private function importExistingGroups(): void
    {
        $nameKey = static fn (string $name): string => hash('sha256', Str::upper(preg_replace('/[\s_-]+/u', '', trim($name)) ?? ''));
        $stamp = now()->format(DB::connection()->getDriverName() === 'sqlsrv' ? 'Y-m-d H:i:s.vP' : 'Y-m-d H:i:s');
        $ids = [];
        $insert = static function (string $name, array $attributes = []) use (&$ids, $nameKey, $stamp): int {
            $name = Str::squish($name);
            if ($name === '' || mb_strlen($name) > 100) {
                throw new RuntimeException('Nama grup lama harus berisi 1–100 karakter sebelum migrasi.');
            }
            $fingerprint = $nameKey($name);
            if (isset($ids[$fingerprint])) {
                return $ids[$fingerprint];
            }
            $key = $attributes['group_key'] ?? 'group-'.Str::ulid();
            if (! is_string($key) || in_array($key, ['all', 'unassigned'], true) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/', $key)
                || DB::table('robot_groups')->where('group_key', $key)->exists()) {
                $key = 'group-'.Str::ulid();
            }
            $ids[$fingerprint] = (int) DB::table('robot_groups')->insertGetId([
                'group_key' => $key, 'name' => $name, 'name_key' => $fingerprint,
                'area_label' => $attributes['area_label'] ?? null,
                'description' => $attributes['description'] ?? null,
                'planned_robots' => $attributes['planned_robots'] ?? null,
                'is_pilot' => (bool) ($attributes['is_pilot'] ?? false),
                'sort_order' => $attributes['sort_order'] ?? 0, 'version' => 1,
                'created_at' => $stamp, 'updated_at' => $stamp,
            ]);

            return $ids[$fingerprint];
        };
        foreach (array_values(config('rms_robot_groups.groups', [])) as $index => $legacy) {
            $insert((string) ($legacy['label'] ?? ''), [
                'group_key' => $legacy['key'] ?? null,
                'planned_robots' => $legacy['planned_robots'] ?? null,
                'is_pilot' => $legacy['pilot'] ?? false, 'sort_order' => ($index + 1) * 10,
                'area_label' => config('rms_robot_groups.area_label'),
            ]);
        }
        foreach (DB::table('robots')->select(['id', 'line_name'])->orderBy('id')->get() as $robot) {
            if (trim((string) $robot->line_name) !== '') {
                $id = $insert((string) $robot->line_name);
                DB::table('robots')->where('id', $robot->id)->update(['robot_group_id' => $id]);
            }
        }
    }
};
