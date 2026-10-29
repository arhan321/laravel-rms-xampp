<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\RobotConnectionEvent;
use Illuminate\Contracts\View\View;

class ConnectionController extends BaseRmsWebController
{
    public function index(): View
    {
        $robots = $this->robotCards($this->robotRegistry());
        return view('rms.connections.index', [
            'title' => 'Data & Connections',
            'robots' => $robots,
            'events' => RobotConnectionEvent::query()->with('robot')
                ->orderByDesc('occurred_at')->paginate(30),
            'connectedCount' => $robots->where('connectivity', 'connected')->count(),
            'staleCount' => $robots->where('connectivity', 'stale')->count(),
            'offlineCount' => $robots->where('connectivity', 'offline')->count(),
            'unknownCount' => $robots->where('connectivity', 'unknown')->count(),
            'architecture' => ['Controller Yaskawa', 'Pengiriman Data', 'Penyimpanan RMS', 'Yaskawa Cockpit'],
        ]);
    }
}
