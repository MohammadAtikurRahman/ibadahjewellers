<?php

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_ibadah',
        'user' => 'cpaneluser_ibadah',
        'password' => 'replace-with-the-database-password',
    ],

    // Generate a unique random token for first-time admin setup. Remove it after setup.
    'setup_token' => '',

    // Turn on after checking your catalogue, quantities and store policies.
    'checkout_enabled' => false,
];
