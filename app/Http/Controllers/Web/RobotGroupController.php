<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use App\Models\RobotGroup;
use App\Services\Rms\RobotGroupStore;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RobotGroupController extends BaseRmsWebController
{
    public function __construct(private readonly RobotGroupStore $store) {}

    public function index(): View
    {
        return view('rms.robot-groups.index', [
            'title' => 'Kelola Grup Robot',
            'groups' => RobotGroup::query()->withCount('robots')->orderBy('sort_order')->orderBy('name')->get(),
            'unassignedCount' => Robot::query()->whereNull('robot_group_id')->count(),
            'writesEnabled' => $this->writesEnabled(),
        ]);
    }

    public function create(): View
    {
        $this->requireWritable();

        return $this->form();
    }

    public function edit(RobotGroup $robotGroup): View
    {
        $this->requireWritable();

        return $this->form($robotGroup);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWritable();
        $group = $this->save($this->validated($request));

        return redirect()->route('rms.robot-groups.edit', $group)
            ->with('group_success', 'Grup robot berhasil dibuat dan anggotanya tersimpan.');
    }

    public function update(Request $request, RobotGroup $robotGroup): RedirectResponse
    {
        $this->requireWritable();
        $group = $this->save($this->validated($request, $robotGroup), $robotGroup);

        return redirect()->route('rms.robot-groups.edit', $group)
            ->with('group_success', 'Informasi grup dan keanggotaan robot berhasil diperbarui.');
    }

    public function destroy(Request $request, RobotGroup $robotGroup): RedirectResponse
    {
        $this->requireWritable();
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->store->delete($robotGroup, (int) $data['version']);

        return redirect()->route('rms.robot-groups.index')->with('group_success', 'Grup kosong berhasil dihapus.');
    }

    private function form(?RobotGroup $group = null): View
    {
        $robots = Robot::query()->with(['controller', 'group'])->orderBy('robot_code')->get();

        return view('rms.robot-groups.form', [
            'title' => $group === null ? 'Tambah Grup Robot' : 'Edit Grup Robot',
            'group' => $group, 'robots' => $robots,
            'selectedRobotIds' => $group === null ? [] : $robots->where('robot_group_id', $group->id)->pluck('id')->all(),
            'membershipToken' => $this->store->membershipToken($robots),
        ]);
    }

    private function validated(Request $request, ?RobotGroup $group = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/[\pL\pN]/u'],
            'area_label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:4000'],
            'planned_robots' => ['nullable', 'integer', 'between:0,10000'],
            'is_pilot' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,10000'],
            'version' => [$group === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'robot_ids' => ['sometimes', 'array', 'max:1000'],
            'robot_ids.*' => ['required', 'integer', 'distinct', 'exists:robots,id'],
            'membership_token' => ['required', 'string', 'size:64'],
            'members_present' => ['required', 'accepted'],
        ], [
            'name.required' => 'Isi nama grup robot.',
            'name.regex' => 'Nama grup harus mengandung huruf atau angka.',
            'robot_ids.*.exists' => 'Robot yang dipilih sudah tidak tersedia. Muat ulang halaman.',
        ]);
        $data['name'] = Str::squish($data['name']);
        $duplicate = RobotGroup::query()->where('name_key', RobotGroup::nameKey($data['name']));
        if ($group !== null) {
            $duplicate->where('id', '!=', $group->id);
        }
        if ($duplicate->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama grup tersebut sudah digunakan. Gunakan nama grup yang berbeda.']);
        }
        $data['is_pilot'] = (bool) $data['is_pilot'];

        return $data;
    }

    private function save(array $data, ?RobotGroup $group = null): RobotGroup
    {
        try {
            return $this->store->save($data, $group);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'Nama grup tersebut baru saja digunakan. Muat ulang halaman dan gunakan nama lain.']);
        }
    }

    private function writesEnabled(): bool
    {
        return (bool) config('rms_robot_group_management.writes_enabled', false);
    }

    private function requireWritable(): void
    {
        abort_unless($this->writesEnabled(), 403, 'Pengelolaan grup robot sedang dinonaktifkan.');
    }
}
