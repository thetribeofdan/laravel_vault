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
        |   - 'kv_engine_version' → 1 or 2 (defaults to 2)
        |     Match the path to the engine: KV v2 uses `/data/`; KV v1 does not.
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
        //     'kv_engine_version' => 2,
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
    |                   Inject secrets into your config so you can call it like this: config('app.api_key').
    |                   This Is Important For Making Secrets mapped through the package Accessible Via
    |                   Laravel's config() Helper.
    | 'cache_ttl'    → Cache duration in seconds (default: 1 hour)
    */

    'override_env' => env('VAULT_OVERRIDE_ENV', true),
    'cache_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Cache and Source Loading
    |--------------------------------------------------------------------------
    |
    | Mapped secrets remain independent cache entries. On a cache miss, the
    | package loads each configured source once and caches only needed values.
    |
    */

    // Null uses the application's default Laravel cache store.
    'cache_store' => env('VAULT_CACHE_STORE'),

    // Use a distinct prefix when applications share a cache backend.
    'cache_prefix' => env('VAULT_CACHE_PREFIX', 'vault'),

    // Bump manually only when intentionally invalidating package cache keys.
    'cache_version' => env('VAULT_CACHE_VERSION', 2),

    // Short TTLs prevent repeated reads for a missing key or failed source.
    'missing_key_ttl' => env('VAULT_MISSING_KEY_TTL', 60),
    'failure_ttl' => env('VAULT_FAILURE_TTL', 15),

    // Used only by cache stores that support atomic locks. The package raises
    // the lease when the configured token request plan needs more time.
    'lock_ttl' => env('VAULT_LOCK_TTL', 60),
    'lock_wait_seconds' => env('VAULT_LOCK_WAIT_SECONDS', 30),

    // Detect Vault Agent file rewrites before cache_ttl expires.
    'file_fingerprinting' => env('VAULT_FILE_FINGERPRINTING', true),

    /*
    |--------------------------------------------------------------------------
    | Token-mode HTTP settings
    |--------------------------------------------------------------------------
    |
    | Sources are pooled where the installed Laravel version supports it. Merge
    | order still follows token_sources, so later sources continue to win.
    |
    */

    'http' => [
        'concurrent' => env('VAULT_HTTP_CONCURRENT', true),
        'max_concurrency' => env('VAULT_HTTP_MAX_CONCURRENCY', 5),
        'connect_timeout' => env('VAULT_HTTP_CONNECT_TIMEOUT', 2),
        'timeout' => env('VAULT_HTTP_TIMEOUT', 5),
        // Total attempts, including the first request.
        'retries' => env('VAULT_HTTP_RETRIES', 3),
        'retry_backoff_ms' => [250, 500, 1000],
        'retry_jitter_ms' => env('VAULT_HTTP_RETRY_JITTER_MS', 100),
    ],





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
