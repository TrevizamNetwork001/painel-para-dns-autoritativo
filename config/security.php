<?php

return [
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'referrer_policy' => env(
            'SECURITY_REFERRER_POLICY',
            'strict-origin-when-cross-origin',
        ),
        'permissions_policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'camera=(), geolocation=(), microphone=(), payment=(), usb=()',
        ),
    ],

    'login' => [
        'email_ip_limit' => (int) env('LOGIN_EMAIL_IP_LIMIT', 5),
        'global_ip_limit' => (int) env('LOGIN_GLOBAL_IP_LIMIT', 20),
        'decay_seconds' => (int) env('LOGIN_RATE_LIMIT_DECAY_SECONDS', 60),
        'audit_dedup_seconds' => (int) env(
            'LOGIN_AUDIT_DEDUP_SECONDS',
            60,
        ),
    ],

    'admin_2fa' => [
        'required' => env('ADMIN_2FA_REQUIRED', true),
        'grace_days' => (int) env('ADMIN_2FA_GRACE_DAYS', 14),
    ],

    'agent_enrollment' => [
        'ttl_minutes' => (int) env('AGENT_ENROLLMENT_TTL_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Empty by default: forwarded headers are ignored. Configure only the
    | explicit Nginx/load-balancer CIDRs that directly connect to PHP.
    |
    */
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
];
