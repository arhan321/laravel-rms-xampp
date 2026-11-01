<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RobotGroupStore
{
    public function membershipToken(Collection $robots): string
    {
        $membership = $robots->sortBy('id')->map(fn (Robot $robot): array => [(int) $robot->id, $robot->robot_group_id])->values()->all();

        return hash_hmac('sha256', json_encode($membership, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function save(array $data, ?RobotGroup $existing = null): RobotGroup
    {
        return DB::transaction(function () use ($data, $existing): RobotGroup {
            $group = $existing === null ? new RobotGroup : RobotGroup::query()->lockForUpdate()->findOrFail($existing->id);
            if ($existing !== null && $group->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Grup telah diubah dari sesi lain. Muat ulang halaman dan periksa perubahan Anda.']);
            }
            $robots = Robot::query()->orderBy('id')->lockForUpdate()->get(['id', 'robot_group_id']);
            if (! hash_equals($this->membershipToken($robots), $data['membership_token'])) {
                throw ValidationException::withMessages(['membership_token' => 'Daftar atau penempatan robot berubah dari sesi lain. Muat ulang halaman sebelum menyimpan.']);
            }
            $group->fill(collect($data)->only(['name', 'area_label', 'description', 'planned_robots', 'is_pilot', 'sort_order'])->all());
            $group->version = $existing === null ? 1 : $group->version + 1;
            $group->save();
            $selected = array_map('intval', $data['robot_ids'] ?? []);
            $stamp = now()->format(DB::connection()->getDriverName() === 'sqlsrv' ? 'Y-m-d H:i:s.vP' : 'Y-m-d H:i:s');
            DB::table('robots')->where('robot_group_id', $group->id)->whereNotIn('id', $selected)
                ->update(['robot_group_id' => null, 'updated_at' => $stamp]);
            if ($selected !== []) {
                DB::table('robots')->whereIn('id', $selected)->where(function (Builder $query) use ($group): void {
                    $query->whereNull('robot_group_id')->orWhere('robot_group_id', '!=', $group->id);
                })->update(['robot_group_id' => $group->id, 'updated_at' => $stamp]);
            }

            return $group;
        }, 3);
    }

    public function delete(RobotGroup $existing, int $version): void
    {
        DB::transaction(function () use ($existing, $version): void {
            $group = RobotGroup::query()->lockForUpdate()->findOrFail($existing->id);
            if ($group->version !== $version) {
                throw ValidationException::withMessages(['version' => 'Grup telah berubah. Muat ulang halaman sebelum menghapus.']);
            }
            if ($group->robots()->exists()) {
                throw ValidationException::withMessages(['group' => 'Grup masih memiliki robot. Pindahkan atau keluarkan anggota melalui Edit grup terlebih dahulu.']);
            }
            $group->delete();
        }, 3);
    }
}
