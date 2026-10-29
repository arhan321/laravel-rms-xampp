<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\MaintenanceLog;
use App\Models\MaintenanceRecommendation;
use App\Models\Robot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operator-facing maintenance recommendations. No login/approval workflow.
 * POST actions require normal web CSRF protection, explicit environment opt-in,
 * rate-limited routes and are intended ONLY for a trusted internal RMS network.
 */
final class RecommendationController extends BaseRmsWebController
{
    public function index(Request $request): View
    {
        $filter = $request->validate([
            'robot_id' => ['nullable', 'integer', 'exists:robots,id'],
            'status' => ['nullable', Rule::in(['all', 'open', 'acknowledged', 'resolved', 'dismissed'])],
            'priority' => ['nullable', 'string', 'max:32'],
            'source_type' => ['nullable', Rule::in(['all', 'automatic', 'manual'])],
        ]);

        $query = MaintenanceRecommendation::query()->with([
            'robot:id,robot_code,name',
            'healthAssessment:id,robot_id,health_status,summary,evaluated_at',
            'maintenancePlan:id,robot_id,title',
        ]);
        if (!empty($filter['robot_id'])) {
            $query->where('robot_id', $filter['robot_id']);
        }
        if (!empty($filter['status']) && $filter['status'] !== 'all') {
            $query->where('status', $filter['status']);
        }
        if (!empty($filter['priority']) && $filter['priority'] !== 'all') {
            $query->where('priority', $filter['priority']);
        }
        if (($filter['source_type'] ?? 'all') === 'manual') {
            $query->where('source', 'manual_operator');
        } elseif (($filter['source_type'] ?? 'all') === 'automatic') {
            $query->where('source', '<>', 'manual_operator');
        }

        $recommendations = $query->orderByDesc('generated_at')
            ->orderByDesc('id')->paginate(15)->withQueryString();

        // Only retrieve actions belonging to the current recommendation page.
        // The FK was added additively; existing journals are preserved.
        $actions = MaintenanceLog::query()
            ->whereIn('maintenance_recommendation_id', $recommendations->getCollection()->pluck('id')->all())
            ->orderByDesc('created_at')->limit(200)
            ->get()->groupBy('maintenance_recommendation_id');

        return view('rms.recommendations.index', [
            'title' => 'Maintenance Recommendations',
            'robots' => Robot::query()->orderBy('robot_code')->get(['id', 'robot_code', 'name']),
            'recommendations' => $recommendations,
            'actionsByRecommendation' => $actions,
            'openCount' => MaintenanceRecommendation::query()->where('status', 'open')->count(),
            'inProgressCount' => MaintenanceRecommendation::query()->where('status', 'acknowledged')->count(),
            'resolvedCount' => MaintenanceRecommendation::query()->where('status', 'resolved')->count(),
            'filter' => $filter,
            'generationEnabled' => (bool) config('rms_recommendations.auto_enabled', false),
            'writesEnabled' => (bool) config('rms_recommendations.writes_enabled', false),
            'note' => 'Rekomendasi bukan perintah menggerakkan robot. Saran otomatis berasal dari penilaian kondisi RMS dan tetap memerlukan inspeksi sesuai SOP.',
        ]);
    }

    /** Add a human observation, not a fabricated controller alarm. */
    public function storeManual(Request $request): RedirectResponse
    {
        $this->requireWritable();
        $data = $request->validate([
            'robot_id' => ['required', 'integer', 'exists:robots,id'],
            'reported_by' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:180'],
            'recommendation_text' => ['required', 'string', 'max:3000'],
            'priority' => ['required', Rule::in(['low', 'normal', 'medium', 'high', 'critical'])],
            'axis' => ['nullable', Rule::in(['S', 'L', 'U', 'R', 'B', 'T'])],
            'finding' => ['nullable', 'string', 'max:3000'],
        ]);

        MaintenanceRecommendation::create([
            'robot_id' => $data['robot_id'],
            'source' => 'manual_operator',
            'priority' => $data['priority'],
            'status' => 'open',
            'title' => $data['title'],
            'recommendation_text' => $data['recommendation_text'],
            'generated_at' => now(),
            'evidence' => [
                'type' => 'operator_observation',
                'reported_by' => trim($data['reported_by']),
                'axis' => $data['axis'] ?? null,
                'finding' => $data['finding'] ?? null,
                'recorded_at' => now()->toIso8601String(),
                'source_label' => 'MANUAL - belum diverifikasi oleh telemetry',
            ],
        ]);

        return back()->with('pm_success', 'Temuan manual ditambahkan. Sumbernya tetap ditandai MANUAL, bukan alarm Yaskawa.');
    }

    /** Mark an open item as being handled, without login or approval. */
    public function acknowledge(Request $request, MaintenanceRecommendation $recommendation): RedirectResponse
    {
        $this->requireWritable();
        $data = $request->validate(['performed_by' => ['required', 'string', 'max:120']]);

        $updated = DB::transaction(function () use ($recommendation, $data): bool {
            $rec = MaintenanceRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if ($rec->status !== 'open') return false;
            $rec->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => trim($data['performed_by']),
            ]);
            return true;
        }, 3);

        return $updated
            ? back()->with('pm_success', 'Rekomendasi ditandai sedang ditangani.')
            : back()->withErrors(['recommendation' => 'Hanya rekomendasi OPEN yang bisa mulai ditangani.']);
    }

    /** One action = one journal entry. Completion never declares robot HEALTHY. */
    public function recordAction(Request $request, MaintenanceRecommendation $recommendation): RedirectResponse
    {
        $this->requireWritable();
        $data = $request->validate([
            'performed_by' => ['required', 'string', 'max:120'],
            'action_type' => ['required', Rule::in(['inspection', 'preventive', 'corrective', 'other'])],
            'result' => ['required', Rule::in(['completed', 'follow_up_required'])],
            'findings' => ['nullable', 'string', 'max:5000'],
            'action_taken' => ['required', 'string', 'max:5000'],
        ]);

        $ok = DB::transaction(function () use ($recommendation, $data): bool {
            $rec = MaintenanceRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if (!in_array($rec->status, ['open', 'acknowledged'], true)) return false;

            $now = now();
            MaintenanceLog::create([
                'robot_id' => $rec->robot_id,
                'maintenance_plan_id' => $rec->maintenance_plan_id,
                'maintenance_recommendation_id' => $rec->id,
                'action_type' => $data['action_type'],
                'result' => $data['result'],
                'performed_by' => trim($data['performed_by']),
                'started_at' => $now,
                'completed_at' => $data['result'] === 'completed' ? $now : null,
                'findings' => $data['findings'] ?? null,
                'action_taken' => $data['action_taken'],
                'supporting_data' => [
                    'source' => 'rms_recommendation_operator',
                    'recommendation_id' => $rec->id,
                    'recommendation_source' => $rec->source,
                ],
            ]);
            if ($data['result'] === 'completed') {
                $rec->update([
                    'status' => 'resolved',
                    'acknowledged_at' => $rec->acknowledged_at ?? $now,
                    'acknowledged_by' => $rec->acknowledged_by ?? trim($data['performed_by']),
                    'closed_at' => $now,
                    'closure_note' => $data['action_taken'],
                ]);
            } else {
                $rec->update([
                    'status' => 'acknowledged',
                    'acknowledged_at' => $rec->acknowledged_at ?? $now,
                    'acknowledged_by' => $rec->acknowledged_by ?? trim($data['performed_by']),
                ]);
            }
            return true;
        }, 3);

        return $ok
            ? back()->with('pm_success', $data['result'] === 'completed'
                ? 'Pekerjaan dicatat selesai dan masuk Maintenance Journal. Robot Health tetap harus dievaluasi ulang.'
                : 'Hasil inspeksi disimpan di Maintenance Journal. Rekomendasi tetap sedang ditangani.')
            : back()->withErrors(['recommendation' => 'Rekomendasi sudah ditutup. Muat ulang halaman.']);
    }

    /** Close incorrect or non-applicable advice with an explicit reason. */
    public function dismiss(Request $request, MaintenanceRecommendation $recommendation): RedirectResponse
    {
        $this->requireWritable();
        $data = $request->validate([
            'performed_by' => ['required', 'string', 'max:120'],
            'closure_note' => ['required', 'string', 'min:8', 'max:2000'],
        ]);
        $ok = DB::transaction(function () use ($recommendation, $data): bool {
            $rec = MaintenanceRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if (!in_array($rec->status, ['open', 'acknowledged'], true)) return false;
            $rec->update([
                'status' => 'dismissed',
                'closed_at' => now(),
                'closure_note' => 'Tidak relevan — oleh '.trim($data['performed_by']).': '.$data['closure_note'],
            ]);
            return true;
        }, 3);
        return $ok
            ? back()->with('pm_success', 'Rekomendasi ditutup sebagai tidak relevan. Alasannya tersimpan.')
            : back()->withErrors(['recommendation' => 'Rekomendasi sudah ditutup.']);
    }

    private function requireWritable(): void
    {
        abort_unless((bool) config('rms_recommendations.writes_enabled', false), 403,
            'Mode pencatatan rekomendasi sedang dinonaktifkan. Hubungi administrator RMS.');
    }
}
