<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RobotGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class RobotGroup extends RmsModel
{
    /** @use HasFactory<RobotGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'area_label', 'description', 'planned_robots', 'is_pilot', 'sort_order'];

    protected function casts(): array
    {
        return ['planned_robots' => 'integer', 'is_pilot' => 'boolean', 'sort_order' => 'integer',
            'version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $group): void {
            $group->name = Str::squish($group->name);
            $group->name_key = self::nameKey($group->name);
            if (! $group->exists && ! $group->group_key) {
                $group->group_key = 'group-'.Str::ulid();
            }
        });
    }

    public static function nameKey(string $name): string
    {
        return hash('sha256', Str::upper(preg_replace('/[\s_-]+/u', '', trim($name)) ?? ''));
    }

    public function robots(): HasMany
    {
        return $this->hasMany(Robot::class, 'robot_group_id');
    }
}
