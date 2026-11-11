<?php

// Additional read-only Job Torque routes. Included from routes/web.php.
// This file is required only once and must remain in the standard WEB middleware group.
\Illuminate\Support\Facades\Route::get('/rms/robots/{robot}/job-torque',
    [\App\Http\Controllers\Web\JobTorqueController::class, 'index'])
    ->whereNumber('robot')->name('rms.robots.job-torque');
\Illuminate\Support\Facades\Route::get('/rms/robots/{robot}/job-torque/export',
    [\App\Http\Controllers\Web\JobTorqueController::class, 'export'])
    ->whereNumber('robot')->middleware('throttle:10,1')->name('rms.robots.job-torque.export');
