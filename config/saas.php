<?php

/*
| SaaS plans. Limits are per workspace per calendar month (messages, import rows)
| or totals (documents, members, agents, datasets). -1 = unlimited.
| platform_keys: may the workspace use YOUR provider keys from .env? If false it
| must bring its own keys (Settings > AI providers).
*/

return [
    'name' => env('SAAS_NAME', env('APP_NAME', 'AI Business Brain')),

    'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),

    'allow_registration' => env('SAAS_ALLOW_REGISTRATION', true),

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'stripe_price' => null,
            'limits' => ['messages' => 100, 'documents' => 10, 'members' => 1, 'agents' => 1, 'datasets' => 1, 'import_rows' => 1000],
            'features' => ['export'],
            'platform_keys' => false,
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 29,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
            'limits' => ['messages' => 3000, 'documents' => 300, 'members' => 5, 'agents' => 5, 'datasets' => 10, 'import_rows' => 50000],
            'features' => ['export', 'api', 'widget', 'telegram', 'whatsapp', 'slack', 'webhooks', 'briefings', 'scheduled_imports', 'url_imports'],
            'platform_keys' => true,
        ],
        'business' => [
            'name' => 'Business',
            'price' => 99,
            'stripe_price' => env('STRIPE_PRICE_BUSINESS'),
            'limits' => ['messages' => 25000, 'documents' => 5000, 'members' => 25, 'agents' => 50, 'datasets' => 100, 'import_rows' => 1000000],
            'features' => ['export', 'api', 'widget', 'telegram', 'whatsapp', 'slack', 'webhooks', 'briefings', 'scheduled_imports', 'url_imports', 'database_imports', 'audit_log', 'white_label'],
            'platform_keys' => true,
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price' => (int) env('SAAS_ENTERPRISE_PRICE', 499),
            'stripe_price' => env('STRIPE_PRICE_ENTERPRISE'),
            'limits' => ['messages' => -1, 'documents' => -1, 'members' => -1, 'agents' => -1, 'datasets' => -1, 'import_rows' => -1],
            'features' => ['export', 'api', 'widget', 'telegram', 'whatsapp', 'slack', 'webhooks', 'briefings', 'scheduled_imports', 'url_imports', 'database_imports', 'audit_log', 'white_label', 'hipaa'],
            'platform_keys' => true,
        ],
    ],

    'default_plan' => 'free',
];
