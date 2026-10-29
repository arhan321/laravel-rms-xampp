<?php

declare(strict_types=1);

namespace App\Services\Rms;

final class MaintenanceCategories
{
    public const LABELS = [
        'inspection' => 'Inspection',
        'grease_supply' => 'Grease supply',
        'grease_exchange' => 'Grease exchange',
        'cover_contact_lead' => 'Cover / contact / lead',
        'lead_wire' => 'Lead wire exchange',
        'overhaul' => 'Overhaul',
        'battery' => 'Battery exchange',
        'cooling_fan' => 'Cooling fan',
        'capacitor' => 'Capacitor',
        'amplifier_igbt' => 'Amplifier IGBT',
        'contactor' => 'Contactor',
        'other' => 'Lainnya',
    ];
}
