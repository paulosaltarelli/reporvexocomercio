<?php

return [
    'smtp_settings_path' => '/var/lib/vendra/smtp-admin/settings.enc',
    'mfa_required' => env('PLATFORM_MFA_REQUIRED', false),
];
