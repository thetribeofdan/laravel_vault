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
        if (file_exists(__DIR__ . '/helpers.php')) {
            require_once __DIR__ . '/helpers.php';
        }

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

            // $cachedKeys = $this->cacheTableExists()
            //     ? \Illuminate\Support\Facades\Cache::get($cacheKey)
            //     : null;
            // if (!$this->cacheTableExists() && config('cache.default') === 'database') {
            //     config(['cache.default' => 'file']);
            // }
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
            // if (app()->environment('local')) {
            //     // Stubbed secrets for local/dev
            //     return [
            //         "NIBSS_BVN_STAGING_CLIENT_ID" => "fjnsncwinwjcnss",
            //         "NIBSS_BVN_STAGING_CLIENT_SECRET" => "fjncnsjcnwcnjwnc",
            //         // Add more mock/test values if needed
            //     ];
            // }

            $mode = config('vault.mode', 'file');

            if ($mode === 'file') {
                return $this->loadFromFile();
            }

            if ($mode === 'token') {
                return $this->loadFromVaultWithToken();
            }

            \Log::error("Vault mode '{$mode}' is not supported.");
            return [];

        } catch (\Throwable $e) {
            \Log::error("Vault load error: {$e->getMessage()}");
            return [];
        }
    }


    protected function loadFromFile(): array
    {
        $filePath = config('vault.file_path');
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!$lines) {
            \Log::error("Vault file not found or empty: {$filePath}");
            return [];
        }

        $data = [];

        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $data[trim($key)] = trim($value);
            }
        }

        return $data;
    }


    protected function loadFromVaultWithToken(): array
    {
        try {
            $token = config('vault.token');
            $path = config('vault.vault_path');
            $url = rtrim(config('vault.vault_url'), '/') . $path;

            $response = retry(3, function () use ($token, $url) {
                return \Illuminate\Support\Facades\Http::timeout(10)->withHeaders([
                    'X-Vault-Token' => $token,
                ])->get($url);
            }, 1000);

            if ($response->failed()) {
                \Log::error("Failed to connect to Vault: " . $response->body());
                return [];
            }

            $json = $response->json();

            if (!empty($json['data']['data'])) {
                return $json['data']['data'];
            }

            \Log::warning("Vault response missing data: " . json_encode($json));
            return [];

        } catch (\Throwable $e) {
            \Log::error("Vault token mode error: {$e->getMessage()}");
            return [];
        }
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
