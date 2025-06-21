<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 🚀 Vault Mode
    |--------------------------------------------------------------------------
    | Tell the package how you'd like to fetch your secrets!
    |
    | Options:
    |   - 'file'  → Load secrets from one or more static files (.env or .json)
    |   - 'token' → Connect to a live Vault service using tokens
    |
    | Default: 'file'
    */

    'mode' => env('VAULT_MODE', 'file'),


    /*
    |--------------------------------------------------------------------------
    | 📁 File Mode Settings
    |--------------------------------------------------------------------------
    | List the full paths to secret files here.
    |
    | Supported formats:
    |   - .env-style files → MY_KEY=value
    |   - JSON files       → { "MY_KEY": "value" }
    |
    | Example:
    |   base_path('.vault/secrets.env'),
    |   base_path('.vault/creds.json'),
    */

    'file_paths' => [
        // env('VAULT_SECRET_FILE_1', base_path('.env')),
        // env('VAULT_SECRET_FILE_2', base_path('.env'))
    ],


    /*
        |--------------------------------------------------------------------------
        | 🔐 Token Mode Settings
        |--------------------------------------------------------------------------
        | Connect to live Vault endpoints securely using token(s).
        | You can specify multiple token+path+url combinations to pull from
        | different secret engines.
        |
        | Each block should contain:
        |   - 'token' → Your Vault access token
        |   - 'path'  → The Vault KV path to your secrets
        |   - 'url'   → Base URL of your Vault instance
        |
        | Example:
        | [
        |     'token' => env('VAULT_TOKEN'),
        |     'path'  => '/v1/secret/data/billing',
        |     'url'   => env('VAULT_URL'),
        | ]
    */

    'token_sources' => [
        // [
        //     'token' => env('VAULT_TOKEN'),
        //     'path' => '/v1/secret/data/billing',
        //     'url' => env('VAULT_URL'),
        // ],
        // [
        //
        // ],
    ],




    /*
    |--------------------------------------------------------------------------
    | ⚙️ General Settings
    |--------------------------------------------------------------------------
    | 'override_env' → Should secrets override your Laravel config? This Lets the package know you want to
    |                   Inject secrets into your config so you can call it like this: config('app.api_key')
    | 'cache_key'    → Cache key used for storing loaded secrets
    | 'cache_ttl'    → Cache duration in seconds (default: 1 hour)
    */

    'override_env' => env('VAULT_OVERRIDE_ENV', true),
    'cache_key' => 'CLOUD_KEYS',
    'cache_ttl' => 3600, //seconds





    /*
     |--------------------------------------------------------------------------
     | 🗺️ Vault-to-Config Key Mapping
     |--------------------------------------------------------------------------
     | Tell the package where to inject your secrets in Laravel's config.
     |
     | Format:
     |   'laravel.config.key' => 'VAULT_SECRET_KEY'
     |
     | 🧠 Big Brain Tip: You can define this part based on your app’s config structure.

     | Examples:
     |   'app.api_key' => 'API_KEY',
     |   'services.mailgun.secret' => 'MAILGUN_SECRET_KEY',
     |   'services.payment.secret' => 'STRIPE_LIVE_SECRET',
     |   'app.my_api_password' => env('APP_ENV') === 'local' ? 'STAGING_API_KEY' : 'LIVE_API_KEY',
     |
     |
     | Usage(You Call the secret Key Like This In Your Code):
     |   config('app.api_key') → returns your Vault secret 🎯
     |   config('services.mailgun.secret') → returns your Vault secret 🎯
     |   config('services.payment.secret') → returns your Vault secret 🎯
     |   config('app.my_api_password') → returns your Vault secret 🎯
     |
     |
     */
    'map' => [

    ],


];
