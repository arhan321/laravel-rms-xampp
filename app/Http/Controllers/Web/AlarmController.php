<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmHistoryEntry;
use App\Models\RobotAlarmSnapshot;
use App\Services\Rms\AlarmCountermeasureContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Alarm Center: read-only events and SDK snapshots.
 * Multi-robot filtering uses robot_ids[]; old robot_id links still work.
 */
class AlarmController extends BaseRmsWebController
{
    public function index(Request $request, AlarmCountermeasureContext $countermeasureContext): View
    {
        $filter = $request->validate([
            'robot_ids' => ['sometimes', 'array', 'max:100'],
            'robot_ids.*' => ['required', 'integer', 'distinct', 'exists:robots,id'],
            'robot_id' => ['nullable', 'integer', 'exists:robots,id'], // Legacy bookmark support
            'status' => ['nullable', 'in:all,active,cleared,unknown'],
            'severity' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'in:all,Major,Minor,UserSystem,User,Offline'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // Empty selection means all robots. `robot_ids[]` takes priority over old `robot_id`.
        $selectedRobotIds = [];
        if ($request->exists('robot_ids')) {
            $selectedRobotIds = array_values(array_unique(array_map('intval', $filter['robot_ids'] ?? [])));
        } elseif (! empty($filter['robot_id'])) {
            $selectedRobotIds = [(int) $filter['robot_id']];
        }
        $filter['robot_ids'] = $selectedRobotIds;

        $eventsQuery = RobotAlarmEvent::query()->with('robot');
        $snapshotsQuery = RobotAlarmSnapshot::query()->with('robot');
        $historyQuery = RobotAlarmHistoryEntry::query()->with('robot');
        $activeCountQuery = RobotAlarmEvent::query()->where('status', 'active');

        if ($selectedRobotIds !== []) {
            // Same robot selection applies to event table, snapshots and active count.
            $eventsQuery->whereIn('robot_id', $selectedRobotIds);
            $snapshotsQuery->whereIn('robot_id', $selectedRobotIds);
            $historyQuery->whereIn('robot_id', $selectedRobotIds);
            $activeCountQuery->whereIn('robot_id', $selectedRobotIds);
        }

        $status = $filter['status'] ?? 'all';
        if ($status !== 'all') {
            $eventsQuery->where('status', $status);
        }

        $severity = $filter['severity'] ?? 'all';
        if ($severity !== 'all') {
            $eventsQuery->where('severity', $severity);
        }

        $search = trim((string) ($filter['search'] ?? ''));
        if ($search !== '') {
            // Treat the search as a literal substring, including LIKE wildcards.
            // An explicit escape character works for SQL Server and PostgreSQL.
            $needle = '%'.str_replace(
                ['!', '%', '_', '['],
                ['!!', '!%', '!_', '!['],
                $search,
            ).'%';

            foreach ([$eventsQuery, $historyQuery] as $searchQuery) {
                $grammar = $searchQuery->getQuery()->getGrammar();
                $driver = $searchQuery->getConnection()->getDriverName();
                $codeColumn = $grammar->wrap('alarm_code');
                $nameColumn = $grammar->wrap('alarm_name');
                $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
                // Keep case-insensitive search even on a case-sensitive SQL Server DB.
                // This is a fixed server-side collation, never request input.
                $collation = $driver === 'sqlsrv' ? ' COLLATE Latin1_General_100_CI_AS_SC' : '';

                $searchQuery->where(static function ($query) use (
                    $needle, $codeColumn, $nameColumn, $operator, $collation
                ): void {
                    $query->whereRaw(
                        "$codeColumn$collation $operator ? ESCAPE '!'",
                        [$needle],
                    )->orWhereRaw(
                        "$nameColumn$collation $operator ? ESCAPE '!'",
                        [$needle],
                    );
                });
            }
        }
        if (($filter['category'] ?? 'all') !== 'all') {
            $historyQuery->where('category', $filter['category']);
        }

        $snapshots = $snapshotsQuery->orderByDesc('sampled_at')->orderByDesc('id')->limit(30)->get();

        return view('rms.alarms.index', [
            'title' => 'Alarm Center',
            'robots' => Robot::query()->orderBy('robot_code')->get(['id', 'robot_code', 'name']),
            'events' => $eventsQuery
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->paginate(20, ['*'], 'event_page')
                ->withQueryString(),
            'snapshots' => $snapshots,
            'snapshotCountermeasureAlarms' => $snapshots->mapWithKeys(
                fn (RobotAlarmSnapshot $snapshot): array => [$snapshot->id => $countermeasureContext->snapshotAlarms($snapshot)]
            ),
            'historyEntries' => $historyQuery->orderByDesc('first_observed_at')->orderByDesc('id')
                ->paginate(20, ['*'], 'history_page')->withQueryString(),
            // Overall active count within chosen robots, regardless of status/search filter.
            'activeEventCount' => $activeCountQuery->count(),
            'filter' => $filter,
            'parsingNote' => 'Alarm readings are not automatically treated as verified Controller Alarm Events.',
        ]);
    }
}
