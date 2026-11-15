<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Services\Rms\SpeedReducerOemImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class SpeedReducerCockpitController extends Controller
{
    public function show(Request $request, Robot $robot): JsonResponse
    {
        $filter = $request->validate(['group' => ['nullable', 'regex:'.SpeedReducerOemImport::GROUP_PATTERN]]);
        $rows = DB::table('robot_speed_reducer_readings')->where('robot_id', $robot->getKey());
        $groups = (clone $rows)->distinct()->orderBy('control_group')->pluck('control_group');
        $preferred = strtoupper((string) $robot->control_group);
        $default = preg_match(SpeedReducerOemImport::GROUP_PATTERN, $preferred) ? $preferred : 'R1';
        $group = $filter['group'] ?? ($groups->contains($preferred) ? $preferred : ($groups->first() ?? $default));

        $history = $rows->where('control_group', $group)
            ->select([
                'axis', 'date_wib', 'measurement_pct', 'variation_pp', 'operating_hours',
                'remaining_life_hours', 'variation_lower_pp', 'variation_upper_pp',
                'life_lower_hours', 'life_upper_hours', 'source_reference',
            ])
            ->orderByDesc('date_wib')->orderBy('axis')->limit(6000)
            ->get();

        return response()->json([
            'groups' => $groups->push($group)->unique()->values(),
            'group' => $group,
            'rows' => $history->reverse()->values(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Robot $robot, SpeedReducerOemImport $import): RedirectResponse
    {
        try {
            $data = $request->validateWithBag('reducerOem', [
                'csv' => ['required', 'string', 'max:500000'],
                'source_reference' => ['required', 'string', 'max:255'],
                'verified_by' => ['required', 'string', 'max:120'],
                'verified' => ['accepted'],
            ]);

            $count = $import->store((int) $robot->getKey(), $data['csv'], $data['source_reference'], $data['verified_by']);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('reducerOem')
                ->redirectTo(route('rms.robots.configuration', $robot).'#speed-reducer-oem');
        }

        Log::info('rms.reducer.oem_imported', ['robot_id' => $robot->getKey(), 'rows' => $count]);

        return redirect()->to(route('rms.robots.cockpit', $robot).'#speed-reducer-diagnosis')
            ->with('reducer_oem_success', "{$count} baris hasil diagnosis Yaskawa tersimpan.");
    }
}
