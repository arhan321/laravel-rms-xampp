<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use Illuminate\Contracts\View\View;

class HardwareController extends BaseRmsWebController
{
    public function index(): View
    {
        return view('rms.hardware.index', [
            'title' => 'Hardware Insights',
            'estimationAvailable' => false,
            'estimatedRemainingLife' => null,
            'researchItems' => [
                'Cocokkan nilai diagnosis controller dengan pendant dan dokumen Yaskawa.',
                'Kumpulkan dan periksa histori kondisi komponen jangka panjang.',
                'Tentukan kondisi pembanding, batas pemeriksaan, dan prosedur review maintenance.',
            ],
            'note' => 'Hardware life prediction is under investigation, not a deployed feature.',
        ]);
    }
}
