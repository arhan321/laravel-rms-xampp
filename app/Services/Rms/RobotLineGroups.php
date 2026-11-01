<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotGroup;
use Illuminate\Support\Collection;

/** The group catalogue and robot membership are read exclusively from the database. */
final class RobotLineGroups
{
    public function keyForRobot(Robot $robot): string
    {
        return $robot->group?->group_key ?? 'unassigned';
    }

    /** A database lookup retained for bookmarks/import integrations; never assigns a robot. */
    public function keyForLine(?string $line): string
    {
        return trim($line ?? '') === '' ? 'unassigned'
            : (RobotGroup::query()->where('name_key', RobotGroup::nameKey($line))->value('group_key') ?? 'unassigned');
    }

    /** @param Collection<int, array<string, mixed>> $cards */
    public function groups(Collection $cards): Collection
    {
        $groups = RobotGroup::query()->orderBy('sort_order')->orderBy('name')->get()
            ->mapWithKeys(fn (RobotGroup $group): array => [$group->group_key => [
                'id' => $group->id, 'key' => $group->group_key, 'label' => $group->name,
                'area_label' => $group->area_label, 'description' => $group->description,
                'planned_robots' => $group->planned_robots, 'pilot' => $group->is_pilot,
                'additional' => false, 'registered' => 0, 'fresh' => 0, 'attention' => 0,
            ]]);
        foreach ($cards as $card) {
            $key = $this->keyForRobot($card['robot']);
            $group = $groups->get($key) ?? [
                'id' => null, 'key' => 'unassigned', 'label' => 'Belum dikelompokkan',
                'area_label' => null, 'description' => 'Pilih grup untuk robot melalui Edit data robot atau Kelola grup.',
                'planned_robots' => null, 'pilot' => false, 'additional' => true,
                'registered' => 0, 'fresh' => 0, 'attention' => 0,
            ];
            $group['registered']++;
            $group['fresh'] += $card['connectivity'] === 'connected' ? 1 : 0;
            $group['attention'] += in_array($card['health_display']['status'], ['warning', 'upnormal'], true) ? 1 : 0;
            $groups->put($key, $group);
        }

        return $groups->values();
    }

    public function summary(Collection $cards): array
    {
        return [
            'registered' => $cards->count(),
            'fresh' => $cards->where('connectivity', 'connected')->count(),
            'attention' => $cards->filter(fn (array $card): bool => in_array($card['health_display']['status'], ['warning', 'upnormal'], true))->count(),
        ];
    }
}
