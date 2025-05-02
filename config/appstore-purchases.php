<?php

use ReceiptValidator\Environment;

return [
    'validators' => [
        'apple' => [
            'validator' => 'apple-app-store',
            'key_path' => app_path('resources/keys/authkey_ABC123XYZ.p8'),
            'key_id' => 'ABC123XYZ',
            'issuer_id' => 'DEF456UVW',
            'bundle_id' => 'com.example',
            'environment' => Environment::SANDBOX->name,
        ],
        'itunes' => [
            'validator' => 'itunes',
            'shared_secret' => 'SHARED_SECRET',
        ],
    ],
];
