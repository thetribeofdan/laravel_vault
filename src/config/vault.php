<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FILE MODE
    |--------------------------------------------------------------------------
    |  Which mode to use: 'file' or 'token'
    */

    'mode' => env('VAULT_MODE', 'file'),


    /*
    |--------------------------------------------------------------------------
    | FILE MODE SETTINGS
    |--------------------------------------------------------------------------
    */
    'file_paths' => [
        env('VAULT_SECRET_FILE_1', base_path('.env')),
        env('VAULT_SECRET_FILE_2', base_path('.env'))
    ],


    /*
    |--------------------------------------------------------------------------
    | TOKEN MODE SETTINGS
    |--------------------------------------------------------------------------
    */
    'token_sources' => [
        [
            'token' => env('VAULT_TOKEN'),
            'path' => '/v1/secret/data/billing',
            'url' => env('VAULT_URL'),
        ],
        [
            'token' => env('VAULT_ALT_TOKEN'),
            'path' => '/v1/secret/data/payments',
            'url' => env('VAULT_URL'),
        ],
    ],




    /*
    |--------------------------------------------------------------------------
    | Other Settings
    |--------------------------------------------------------------------------
    */
    'override_env' => env('VAULT_OVERRIDE_ENV', true),
    'cache_key' => 'CLOUD_KEYS',
    'cache_ttl' => 3600, //seconds





/*
    |--------------------------------------------------------------------------
    | Vault-to-Config Key Mapping
    |--------------------------------------------------------------------------
    | Define how secrets from Vault should be injected into your Laravel config.
    | Keys on the left are Laravel config keys, values on the right are the Vault keys.
    | 🧠 Developers(The Bad Guys👀) define this
    |
    | Format A: 'config.key' => 'vault_key'
    | Format B: 'key' => 'vault_key'
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
