<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application mode
    |--------------------------------------------------------------------------
    |
    | "warehouse" — slim WMS: stock ±, warehouse-scoped users, Spoolman calc.
    | "retail"    — full POS (default upstream behaviour).
    |
    */
    'mode' => env('APP_MODE', 'warehouse'),

    'is_warehouse' => env('APP_MODE', 'warehouse') === 'warehouse',

    /*
    | Route name prefixes blocked when APP_MODE=warehouse (middleware).
    */
    'blocked_route_prefixes' => [
        'transactions.',
        'sales-returns.',
        'receivables.',
        'aging.',
        'discount-approvals.',
        'members.',
        'pricing-rules.',
        'customer-vouchers.',
        'customer-segments.',
        'crm-campaigns.',
        'crm-reminders.',
        'dine-',
        'dine.',
        'reports.',
        'cashier-shifts.',
        'settings.payments.',
        'settings.loyalty',
        'settings.target',
        'settings.whatsapp',
        'price-lists.',
        'customers.',
        'portal.',
        'export.customers',
        'export.transactions',
        'import.customers',
        // Upstream open-source marketing pages; not for the company domain.
        'features.',
        'documentation.',
        'roadmap.',
        'contributing.',
    ],

    /*
    | Menu section titles (i18n keys under sidebar.sections.*) hidden in warehouse mode.
    | Matched against the English section keys used in Menu.jsx.
    */
    'hidden_menu_sections' => [
        'sales',
        'approval',
        'crmPricing',
        'dineIn',
        'reports',
    ],
];
