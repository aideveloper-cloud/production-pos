<?php

/*
| K Garden central access: people and their WMS access are managed in ERPNext (the core).
| Off by default; while off, the WMS signs in exactly as before (local passwords, warehouse PINs).
*/

return [
    'enabled' => (bool) env('KGARDEN_ACCESS_ENABLED', false),

    // ERPNext base URL, e.g. https://erp.kgarden.co.th
    'erp_url' => rtrim((string) env('KGARDEN_ERP_URL', ''), '/'),

    // KG App code of this application in ERPNext.
    'app_code' => env('KGARDEN_APP_CODE', 'WMS'),

    // OpenID Connect client registered in ERPNext for the WMS ("เข้าสู่ระบบด้วย K Garden").
    'oidc' => [
        'client_id' => env('KGARDEN_OIDC_CLIENT_ID'),
        'client_secret' => env('KGARDEN_OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('KGARDEN_OIDC_REDIRECT_URI'),
    ],

    // Service account in ERPNext with role "KG Access Service": checks PINs and access changes.
    'service' => [
        'api_key' => env('KGARDEN_ACCESS_API_KEY'),
        'api_secret' => env('KGARDEN_ACCESS_API_SECRET'),
    ],

    // ERPNext warehouse names are "<WMS warehouse name><suffix>" (the stock bridge creates them so).
    'warehouse_suffix' => env('KGARDEN_WAREHOUSE_SUFFIX', ' - KG'),

    // ERPNext role in the WMS app -> WMS (Spatie) role.
    'roles' => [
        'superadmin' => 'super-admin',
        'admin' => 'warehouse-admin',
        'supervisor' => 'warehouse-admin',
        'operator' => 'warehouse-pos',
    ],

    // Local accounts that may still sign in with a password when central access is on
    // (break-glass, e.g. if ERPNext is down). Everyone else signs in through ERPNext.
    'local_login' => array_values(array_filter(array_map('trim', explode(',', (string) env('KGARDEN_LOCAL_LOGIN', 'superadmin@wms.local'))))),

    // A person signed in at a shared POS tablet is signed out after this many minutes without a touch.
    'pos_idle_minutes' => (int) env('KGARDEN_POS_IDLE_MINUTES', 10),
];
