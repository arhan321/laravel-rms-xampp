<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use Illuminate\Contracts\View\View;

class NotificationController extends BaseRmsWebController
{
    public function index(): View
    {
        // There are no notification_rules / delivery_logs tables in the 11 migrations.
        return view('rms.notifications.index', [
            'title' => 'Notification Center',
            'configured' => false,
            'deliveryEnabled' => false,
            'rules' => collect(),
            'deliveryLogs' => collect(),
            'message' => 'Tujuan penerima, aturan pengiriman, dan riwayat notifikasi belum dikonfigurasi.',
        ]);
    }
}
