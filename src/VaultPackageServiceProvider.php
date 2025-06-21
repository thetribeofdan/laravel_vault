<?php

namespace VaultImplementation\VaultPackage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class VaultPackageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('vault.package', function ($app) {
            return $this;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Log::info("✅ VaultPackageServiceProvider has been loaded.");

        $this->mergeConfigFrom(__DIR__ . '/config/vault.php', 'vault');

        $this->publishes([
            __DIR__ . '/config/vault.php' => config_path('vault.php'),
        ], 'vault-config');

        // Load global helper
        // if (file_exists(__DIR__ . '/helpers.php')) {
        //     require_once __DIR__ . '/helpers.php';
        // }

        // Auto-override config values from vault
        if (config('vault.override_env', true)) {
            $this->injectVaultValuesIntoConfig();
        }
    }

    protected function injectVaultValuesIntoConfig(): void
    {
        $map = config('vault.map', []);

        // Singleton pattern inline (minimal)
        static $cachedKeys = null;

        if ($cachedKeys === null) {
            $this->fallbackToFileCacheIfMissing();
            $cacheKey = config('vault.cache_key', 'CLOUD_KEYS');
            $cachedKeys = \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        // Fallback to fresh load if cache empty
        if (!$cachedKeys || !is_array($cachedKeys)) {
            $cachedKeys = $this->loadSecrets();
            \Illuminate\Support\Facades\Cache::put(
                config('vault.cache_key', 'CLOUD_KEYS'),
                $cachedKeys,
                config('vault.cache_ttl', 3600)
            );
        }

        foreach ($map as $configKey => $vaultKey) {
            config([$configKey => $cachedKeys[$vaultKey] ?? null]);
        }
    }

    protected function loadSecrets(): array
    {
        try {
            return match (config('vault.mode')) {
                'file' => $this->loadFromFiles(),
                'token' => $this->loadFromVaultWithToken(),
                default => [],
            };
        } catch (\Throwable $e) {
            \Log::error("Vault load error: {$e->getMessage()}");
            return [];
        }
    }



    protected function loadFromFiles(): array
    {
        $paths = config('vault.file_paths', []);
        $secrets = [];

        foreach ($paths as $file) {
            if (!file_exists($file)) {
                \Log::warning("VaultPackage: File not found: {$file}");
                continue;
            }

            $extension = pathinfo($file, PATHINFO_EXTENSION);

            if ($extension === 'json') {
                $content = file_get_contents($file);
                $json = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    \Log::error("VaultPackage: Invalid JSON in file: {$file}");
                    continue;
                }

                $secrets = array_merge($secrets, $json);
            } else {
                // Assume .env style (KEY=VALUE)
                $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $secrets[trim($key)] = trim($value);
                    }
                }
            }
        }

        return $secrets;
    }





    protected function loadFromVaultWithToken(): array
    {
        $sources = config('vault.token_sources', []);
        $secrets = [];

        foreach ($sources as $source) {
            $token = $source['token'] ?? null;
            $path = $source['path'] ?? null;
            $url = rtrim($source['url'] ?? '', '/') . $path;

            if (!$token || !$path || !$url) {
                \Log::error("VaultPackage: Missing token/path/url in a token source.");
                continue;
            }

            try {
                $response = retry(3, function () use ($token, $url) {
                    return \Illuminate\Support\Facades\Http::timeout(10)->withHeaders([
                        'X-Vault-Token' => $token,
                    ])->get($url);
                }, 1000);

                if ($response->successful() && isset($response['data']['data'])) {
                    $secrets = array_merge($secrets, $response['data']['data']);
                } else {
                    \Log::warning("VaultPackage: Empty or failed response from Vault for path: {$path}");
                }
            } catch (\Throwable $e) {
                \Log::error("VaultPackage: Vault call error for path {$path} - {$e->getMessage()}");
            }
        }

        return $secrets;
    }




    public function refresh(): void
    {
        $cacheKey = config('vault.cache_key', 'CLOUD_KEYS');

        // Clear existing cache
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        // Load fresh secrets
        $secrets = $this->loadSecrets();

        // Store in cache again
        \Illuminate\Support\Facades\Cache::put(
            $cacheKey,
            $secrets,
            config('vault.cache_ttl', 3600)
        );

        // Re-inject config values
        $this->injectVaultValuesIntoConfig();
    }


    protected function fallbackToFileCacheIfMissing(): void
    {
        if (
            config('cache.default') === 'database' &&
            !\Illuminate\Support\Facades\Schema::hasTable(config('cache.stores.database.table', 'cache'))
        ) {
            config(['cache.default' => 'file']);
            \Log::info("VaultPackage: fallback to file cache as 'cache' table not found. Pro Tip. You have to run default Laravel Migrations for the Cache table before Installing This Package.");
        }
    }


}
