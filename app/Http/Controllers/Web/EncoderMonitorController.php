<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Services\Rms\EncoderMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EncoderMonitorController extends Controller
{
    public function show(Request $request, Robot $robot, EncoderMonitor $monitor): View
    {
        $robot->load('controller');

        return view('rms.robots.encoder', [
            'title' => 'Monitoring Encoder - '.$robot->robot_code, 'robot' => $robot,
            'encoder' => $monitor->recent($robot, $this->limit($request)), 'axisNames' => EncoderMonitor::AXES,
        ]);
    }

    public function live(Request $request, Robot $robot, EncoderMonitor $monitor): JsonResponse
    {
        return response()->json($monitor->recent($robot, $this->limit($request)))
            ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $request, Robot $robot, EncoderMonitor $monitor): StreamedResponse
    {
        $snapshot = $monitor->recent($robot, $this->limit($request));

        return response()->streamDownload(static function () use ($robot, $snapshot): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['robot_id', 'control_group', 'time_wib', 'time_utc', 'status', 'sdk_code', 'sdk_message', 'session', 'sequence',
                ...array_map(static fn (string $axis): string => $axis.'_pulse', EncoderMonitor::AXES),
                ...array_map(static fn (string $axis): string => $axis.'_delta_pulse', EncoderMonitor::AXES), 'interval_seconds'], ',', '"', '');
            foreach (array_reverse($snapshot['rows']) as $row) {
                $values = [$robot->id, $robot->control_group, $row['time_wib'], $row['time_utc'], $row['label'], $row['read_code'],
                    $row['read_message'], $row['session'], $row['sequence'], ...array_values($row['axes']), ...array_values($row['delta']), $row['interval_seconds']];
                $safe = array_map(static fn ($value) => is_string($value) && preg_match('/^\s*[=+@-]/u', $value) ? "'".$value : $value, $values);
                fputcsv($stream, $safe, ',', '"', '');
            }
            fclose($stream);
        }, 'RMS_Encoder_Robot_'.$robot->id.'_'.now()->format('Ymd_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function limit(Request $request): int
    {
        $data = $request->validate(['limit' => ['sometimes', 'integer', Rule::in([120, 300, 600])]]);

        return (int) ($data['limit'] ?? 120);
    }
}
