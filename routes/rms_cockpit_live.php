<?php

declare(strict_types=1);

use App\Http\Controllers\Web\CockpitLiveController;
use Illuminate\Support\Facades\Route;

// Read-only JSON. Same-origin; follows the existing no-login dashboard policy.
// Keep this as a separate complete file; routes/web.php remains unchanged.
Route::middleware(['web', 'throttle:120,1'])
    ->get('/rms/robots/{robot}/cockpit/live', CockpitLiveController::class)
    ->whereNumber('robot')
    ->name('rms.robots.cockpit.live');
