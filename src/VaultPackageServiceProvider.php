<?php

namespace VaultImplementation\VaultPackage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
// use Throwable;

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
        // Log::info("✅ VaultPackageServiceProvider has been loaded.");

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
        $ttl = config('vault.cache_ttl', 3600);

        foreach ($map as $configKey => $vaultKey) {
            $cacheKey = "vault.secret.{$vaultKey}";

            $value = \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($vaultKey) {
                $all = $this->loadSecrets();

                if (!array_key_exists($vaultKey, $all)) {
                    Log::warning("Vault: Secret key [{$vaultKey}] not found in file/Vault source.");
                    // return null;
                }

                return $all[$vaultKey] ?? null;
            });

            config([$configKey => $value]);
        }
    }

    protected function getSecret(string $key): ?string
    {
        $cacheKey = "vault.secret.{$key}";
        $ttl = config('vault.cache_ttl', 3600);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, $ttl, function () use ($key) {
            $all = $this->loadSecrets();  // load from file or Vault
            return $all[$key] ?? null;
        });
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


    protected function resolvePath(string $path): string
    {
        // Absolute on Unix (/etc/...) or Windows (C:\...)
        if (\Illuminate\Support\Str::startsWith($path, ['/', '\\']) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return $path;
        }

        // Otherwise treat as relative to Laravel base_path()
        return base_path($path);
    }



    protected function loadFromFiles(): array
    {
        try{
            $paths = config('vault.file_paths', []);
            $secrets = [];

            foreach ($paths as $file) {
                $resolvedPath = $this->resolvePath($file);

                if (!file_exists($resolvedPath)) {
                    \Log::warning("VaultPackage: File not found. Input: {$file}, Resolved: {$resolvedPath}");
                    continue;
                }

                $extension = pathinfo($resolvedPath, PATHINFO_EXTENSION);

                if ($extension === 'json') {
                    $content = file_get_contents($resolvedPath);
                    $json = json_decode($content, true);

                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                        \Log::error("VaultPackage: Invalid JSON in file: {$resolvedPath}");
                        continue;
                    }

                    $secrets = array_merge($secrets, $json);
                } else {
                    // Assume .env style (KEY=VALUE)
                    $lines = @file($resolvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                    foreach ($lines as $line) {
                        if (str_contains($line, '=')) {
                            [$key, $value] = explode('=', $line, 2);
                            $secrets[trim($key)] = trim($value);
                        }
                    }
                }
            }

            return $secrets;

        }catch(\Throwable $err){
            \Log::error("Unable To Get Secrets From File Path {$file}: ".$err->getMessage());
            return [];
        }
    }





    protected function loadFromVaultWithToken(): array
    {
        try{
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

        }catch(\Throwable $err){
            \Log::error("Unabe to get Secrets from Vault Server: ".$err->getMessage());

            return [];
        }
    }




    public function refresh(): void
    {
        $map = config('vault.map', []);
        foreach ($map as $vaultKey) {
            \Illuminate\Support\Facades\Cache::forget("vault.secret.{$vaultKey}");
        }

        // Re-inject values with fresh secrets
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
