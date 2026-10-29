<?php

declare(strict_types=1);

return [
    // Keep false until YRC1000 pendant hours have matched SDK text.
    'operating_text_verified' => (bool) env('RMS_OPERATING_TEXT_VERIFIED', false),
    'auto_evaluate' => (bool) env('RMS_AUTO_EVALUATE_ENABLED', false),
];
