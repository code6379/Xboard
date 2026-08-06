<?php

return [
    'password' => env('MASK_ANALYSIS_PASSWORD'),
    'cookie_name' => 'mask_analysis_access',
    'cookie_minutes' => 480,
    'login_attempts' => 5,
    'login_decay_seconds' => 900,
];
