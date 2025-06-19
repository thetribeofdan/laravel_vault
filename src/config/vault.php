<?php

return [

    'mode' => env('VAULT_MODE', 'file'), // or 'token'

    'token' => env('VAULT_AUTH_TOKEN', null),

    'vault_url' => env('VAULT_URL', 'http://127.0.0.1:8200'),

    'vault_path' => env('VAULT_PATH', '/v1/secret/data/your-path'),

    'file_path' => env('VAULT_SECRET_FILE', base_path('.env')),

    'cache_key' => 'CLOUD_KEYS',

    'cache_ttl' => 3600, // seconds

    'override_env' => env('VAULT_OVERRIDE_ENV', true),



/*
    |--------------------------------------------------------------------------
    | Vault-to-Config Key Mapping
    |--------------------------------------------------------------------------
    | Define how secrets from Vault should be injected into your Laravel config.
    | Keys on the left are Laravel config keys, values on the right are the Vault keys.
    |
    | Example 1:
    |     'app.my_api_key' => 'MY_SECRET_KEY_FROM_VAULT',
    |
    |   #In Your Laravel Project You call it like this:
    |       config('app.my_api_key')
    | Example 2:
    |     'third_party.my_api_key' => 'MY_SECRET_KEY_FROM_VAULT',
    |
    |   #In Your Laravel Project You call it like this:
    |       config('third_party.my_api_key')
    |
    |
*/

    // 🧠 Developers(The Bad Guys👀) define this
    'map' => [

        // Example
        // 'config.key' => 'VAULT_KEY'

        //Example 1:
        // 'app.my_api_key' => env('APP_ENV') === 'local'
        //     ? 'STAGING_API_KEY'
        //     : 'LIVE_API_KEY',

        // Example 2
        // 'third_party.my_api_key' => 'NIBSS_BVN_CLIENT_SECRET',


        // Add more here
    ],


];
