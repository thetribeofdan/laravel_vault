<?php

namespace VaultImplementation\VaultPackage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class VaultPackageServiceProvider extends ServiceProvider
{
    private const CACHE_ENTRY_MARKER = '__laravel_vault_cache_entry';

    private ?string $cacheScope = null;

    private ?string $cacheScopeIdentity = null;

    /**
     * Register services and merge defaults before the application boots.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/vault.php', 'vault');

        $this->app->singleton('vault.package', function ($app) {
            return $this;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/vault.php' => config_path('vault.php'),
        ], 'vault-config');

        if (config('vault.override_env', true)) {
            $this->injectVaultValuesIntoConfig();
        }
    }

    /**
     * Hydrate config values from individually cached Vault keys. Source data is
     * loaded once when one or more entries need filling.
     *
     * @param  array<int, string>|null  $onlyVaultKeys
     */
    protected function injectVaultValuesIntoConfig(bool $force = false, ?array $onlyVaultKeys = null): void
    {
        $map = $this->mappedSecrets($onlyVaultKeys);

        if ($map === []) {
            return;
        }

        $vaultKeys = $this->uniqueVaultKeys($map);
        $entries = $this->resolveVaultKeys($vaultKeys, $force);

        foreach ($map as $configKey => $vaultKey) {
            config([$configKey => $this->cacheEntryValue($entries[$vaultKey] ?? null)]);
        }
    }

    /**
     * Retained for subclasses that use the previous protected helper.
     */
    protected function getSecret(string $key): ?string
    {
        $entries = $this->resolveVaultKeys([$key]);

        return $this->cacheEntryValue($entries[$key] ?? null);
    }

    /**
     * Synchronize mapped values, loading only cache entries that are absent or
     * stale. This is useful after adding a new mapping at runtime.
     */
    public function sync(): void
    {
        $this->injectVaultValuesIntoConfig();
    }

    /**
     * Refresh all mapped values in one source sweep. A failed source never
     * overwrites an existing usable cache entry.
     */
    public function refresh(): void
    {
        $this->injectVaultValuesIntoConfig(true);
    }

    /**
     * Refresh a single mapped Vault key without invalidating unrelated keys.
     */
    public function refreshKey(string $vaultKey): void
    {
        if ($this->mappedSecrets([$vaultKey]) === []) {
            Log::warning("VaultPackage: Cannot refresh unmapped secret key [{$vaultKey}].");

            return;
        }

        $this->injectVaultValuesIntoConfig(true, [$vaultKey]);
    }

    /**
     * @param  array<int, string>  $vaultKeys
     * @return array<string, array<string, mixed>|null>
     */
    protected function resolveVaultKeys(array $vaultKeys, bool $force = false): array
    {
        if ($vaultKeys === []) {
            return [];
        }

        $entries = $this->readCacheEntries($vaultKeys);
        $explicitForce = $force;
        $force = $explicitForce || $this->fileSourceHasChanged();

        if ($this->keysNeedingLoad($vaultKeys, $entries, $force) === []) {
            return $entries;
        }

        return $this->resolveVaultKeysWithLock($vaultKeys, $entries, $force, $explicitForce);
    }

    /**
     * @param  array<int, string>  $vaultKeys
     * @param  array<string, array<string, mixed>|null>  $entries
     * @return array<string, array<string, mixed>|null>
     */
    protected function resolveVaultKeysWithLock(array $vaultKeys, array $entries, bool $force, bool $explicitForce): array
    {
        try {
            $cache = $this->cache();

            if (!$this->cacheSupportsLocks($cache)) {
                return $this->loadAndCacheEntries($vaultKeys, $entries, $force);
            }

            $lock = $cache->lock($this->cacheLockKey(), $this->sourceLoadLockTtl());
            $deadline = microtime(true) + $this->nonNegativeInt('vault.lock_wait_seconds', 30);

            do {
                if ($lock->get()) {
                    try {
                        // A competing worker may have populated the values while we waited.
                        $entries = $this->readCacheEntries($vaultKeys);
                        $force = $explicitForce || $this->fileSourceHasChanged();

                        return $this->loadAndCacheEntries($vaultKeys, $entries, $force);
                    } finally {
                        $lock->release();
                    }
                }

                usleep(50000);
                $entries = $this->readCacheEntries($vaultKeys);
                $force = $explicitForce || $this->fileSourceHasChanged();

                if ($this->keysNeedingLoad($vaultKeys, $entries, $force) === []) {
                    return $entries;
                }
            } while (microtime(true) < $deadline);

            // Do not turn a slow leader into a request stampede. The next sync
            // can use the leader's cache entries once it finishes.
            Log::warning('VaultPackage: Timed out waiting for the Vault source-load lock; serving the current cache state.');

            return $entries;
        } catch (Throwable $e) {
            Log::warning('VaultPackage: Unable to use the Vault source-load lock; loading without a lock. ' . $e->getMessage());
        }

        return $this->loadAndCacheEntries($vaultKeys, $entries, $force);
    }

    /**
     * @param  array<int, string>  $vaultKeys
     * @param  array<string, array<string, mixed>|null>  $entries
     * @return array<string, array<string, mixed>|null>
     */
    protected function loadAndCacheEntries(array $vaultKeys, array $entries, bool $force): array
    {
        $keysToLoad = $this->keysNeedingLoad($vaultKeys, $entries, $force);

        if ($keysToLoad === []) {
            return $entries;
        }

        $result = $this->loadSecretsWithStatus($keysToLoad);

        if ($result['complete']) {
            $valueEntries = [];
            $missingEntries = [];

            foreach ($keysToLoad as $vaultKey) {
                if (array_key_exists($vaultKey, $result['secrets'])) {
                    $entry = $this->valueCacheEntry($result['secrets'][$vaultKey]);
                    $entries[$vaultKey] = $entry;
                    $valueEntries[$this->cacheKey($vaultKey)] = $entry;
                } else {
                    Log::warning("VaultPackage: Secret key [{$vaultKey}] not found in configured sources.");
                    $entry = $this->missingCacheEntry();
                    $entries[$vaultKey] = $entry;
                    $missingEntries[$this->cacheKey($vaultKey)] = $entry;
                }
            }

            $this->putCacheEntries($valueEntries, $this->positiveInt('vault.cache_ttl', 3600));
            $this->putCacheEntries($missingEntries, $this->positiveInt('vault.missing_key_ttl', 60));

            if (($result['file_fingerprint'] ?? null) !== null) {
                $this->storeFileFingerprint($result['file_fingerprint']);
            }

            return $entries;
        }

        // Source failure is distinct from a key that is genuinely absent. Keep
        // values returned by healthy sources, retain known-good cache values,
        // and briefly cache only the unresolved entries.
        $valueEntries = [];
        $unavailableEntries = [];

        foreach ($keysToLoad as $vaultKey) {
            if (($result['cacheable_values'] ?? false) && array_key_exists($vaultKey, $result['secrets'])) {
                $entry = $this->valueCacheEntry($result['secrets'][$vaultKey]);
                $entries[$vaultKey] = $entry;
                $valueEntries[$this->cacheKey($vaultKey)] = $entry;

                continue;
            }

            if ($this->hasValueCacheEntry($entries[$vaultKey] ?? null)) {
                continue;
            }

            $entry = $this->unavailableCacheEntry();
            $entries[$vaultKey] = $entry;
            $unavailableEntries[$this->cacheKey($vaultKey)] = $entry;
        }

        $this->putCacheEntries($valueEntries, $this->positiveInt('vault.cache_ttl', 3600));
        $this->putCacheEntries($unavailableEntries, $this->positiveInt('vault.failure_ttl', 15));

        if (($result['file_fingerprint'] ?? null) !== null) {
            // Recording an invalid/missing file's fingerprint allows its short
            // failure cache to work while still detecting a later agent rewrite.
            $this->storeFileFingerprint($result['file_fingerprint']);
        }

        return $entries;
    }

    /**
     * @param  array<int, string>  $neededKeys
     * @return array{secrets: array<string, mixed>, complete: bool, cacheable_values: bool, file_fingerprint: ?string}
     */
    protected function loadSecretsWithStatus(array $neededKeys): array
    {
        try {
            return match (config('vault.mode', 'file')) {
                'file' => $this->loadFromFilesWithStatus($neededKeys),
                'token' => $this->loadFromVaultWithTokenWithStatus($neededKeys),
                default => $this->unsupportedModeResult(),
            };
        } catch (Throwable $e) {
            Log::error("VaultPackage: Vault load error: {$e->getMessage()}");

            return $this->failedLoadResult();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadSecrets(): array
    {
        return $this->loadSecretsWithStatus([])['secrets'];
    }

    /**
     * @param  array<int, string>  $neededKeys
     * @return array{secrets: array<string, mixed>, complete: bool, cacheable_values: bool, file_fingerprint: ?string}
     */
    protected function loadFromFilesWithStatus(array $neededKeys): array
    {
        $paths = config('vault.file_paths', []);

        if (!is_array($paths) || $paths === []) {
            Log::warning('VaultPackage: No file paths are configured for file mode.');

            return $this->failedLoadResult();
        }

        $neededLookup = $this->neededKeyLookup($neededKeys);
        $secrets = [];
        $complete = true;
        $fingerprintBefore = $this->fileFingerprintingEnabled() ? $this->currentFileFingerprint() : null;

        foreach ($paths as $file) {
            if (!is_string($file) || trim($file) === '') {
                Log::error('VaultPackage: A configured file path is invalid.');
                $complete = false;

                continue;
            }

            $resolvedPath = $this->resolvePath($file);

            if (!is_file($resolvedPath)) {
                Log::warning("VaultPackage: File not found. Input: {$file}, Resolved: {$resolvedPath}");
                $complete = false;

                continue;
            }

            $extension = strtolower((string) pathinfo($resolvedPath, PATHINFO_EXTENSION));

            if ($extension === 'json') {
                $content = @file_get_contents($resolvedPath);

                if ($content === false) {
                    Log::error("VaultPackage: Unable to read JSON file: {$resolvedPath}");
                    $complete = false;

                    continue;
                }

                $json = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
                    Log::error("VaultPackage: Invalid JSON in file: {$resolvedPath}");
                    $complete = false;

                    continue;
                }

                $this->mergeRequestedSecrets($secrets, $json, $neededLookup);

                continue;
            }

            $lines = @file($resolvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines === false) {
                Log::error("VaultPackage: Unable to read secret file: {$resolvedPath}");
                $complete = false;

                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);

                if ($key === '' || ($neededLookup !== [] && !array_key_exists($key, $neededLookup))) {
                    continue;
                }

                $secrets[$key] = trim($value);
            }
        }

        $fingerprintAfter = $this->fileFingerprintingEnabled() ? $this->currentFileFingerprint() : null;

        $cacheableValues = $fingerprintBefore === null
            || $fingerprintAfter === null
            || $fingerprintBefore === $fingerprintAfter;

        if (!$cacheableValues) {
            Log::warning('VaultPackage: A secret file changed while it was being read; the result will not be cached.');
            $complete = false;
        }

        return [
            'secrets' => $secrets,
            'complete' => $complete,
            'cacheable_values' => $cacheableValues,
            'file_fingerprint' => $fingerprintBefore !== null && $fingerprintBefore === $fingerprintAfter
                ? $fingerprintAfter
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadFromFiles(): array
    {
        return $this->loadFromFilesWithStatus([])['secrets'];
    }

    /**
     * @param  array<int, string>  $neededKeys
     * @return array{secrets: array<string, mixed>, complete: bool, cacheable_values: bool, file_fingerprint: ?string}
     */
    protected function loadFromVaultWithTokenWithStatus(array $neededKeys): array
    {
        $configuredSources = config('vault.token_sources', []);

        if (!is_array($configuredSources) || $configuredSources === []) {
            Log::warning('VaultPackage: No token sources are configured for token mode.');

            return $this->failedLoadResult();
        }

        $sources = [];
        $complete = true;
        $position = 0;

        foreach ($configuredSources as $source) {
            $prepared = $this->prepareTokenSource($source, $position++);

            if ($prepared === null) {
                $complete = false;

                continue;
            }

            $sources[] = $prepared;
        }

        if ($sources === []) {
            return $this->failedLoadResult();
        }

        $responses = $this->requestVaultSources($sources);
        $neededLookup = $this->neededKeyLookup($neededKeys);
        $secrets = [];

        // Merge in configuration order, not response-arrival order. Later
        // sources therefore retain the package's existing precedence rule.
        foreach ($sources as $source) {
            $outcome = $responses[$source['id']] ?? new \RuntimeException('No response was returned for this Vault source.');

            if (!$this->isSuccessfulResponse($outcome)) {
                $this->logVaultRequestFailure($source, $outcome);
                $complete = false;

                continue;
            }

            $payload = $this->extractVaultPayload($outcome, $source);

            if ($payload === null) {
                $complete = false;

                continue;
            }

            $this->mergeRequestedSecrets($secrets, $payload, $neededLookup);
        }

        return [
            'secrets' => $secrets,
            'complete' => $complete,
            'cacheable_values' => true,
            'file_fingerprint' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadFromVaultWithToken(): array
    {
        return $this->loadFromVaultWithTokenWithStatus([])['secrets'];
    }

    /**
     * @param  mixed  $source
     * @return array{id: string, token: string, path: string, url: string, kv_engine_version: int}|null
     */
    protected function prepareTokenSource($source, int $position): ?array
    {
        if (!is_array($source)) {
            Log::error('VaultPackage: A token source must be an array.');

            return null;
        }

        $token = $source['token'] ?? null;
        $path = $source['path'] ?? null;
        $baseUrl = $source['url'] ?? null;

        if (!is_scalar($token) || trim((string) $token) === '' || !is_scalar($path) || trim((string) $path) === '' || !is_scalar($baseUrl) || trim((string) $baseUrl) === '') {
            Log::error('VaultPackage: Missing token, path, or URL in a token source.');

            return null;
        }

        $baseUrl = trim((string) $baseUrl);
        $path = trim((string) $path);

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            Log::error("VaultPackage: Invalid Vault URL configured for path: {$path}");

            return null;
        }

        $kvVersion = (int) ($source['kv_engine_version'] ?? 2);

        if (!in_array($kvVersion, [1, 2], true)) {
            Log::error("VaultPackage: Unknown KV engine version [{$kvVersion}] for path: {$path}");

            return null;
        }

        return [
            'id' => 'source-' . $position,
            'token' => (string) $token,
            'path' => $path,
            'url' => rtrim($baseUrl, '/') . '/' . ltrim($path, '/'),
            'kv_engine_version' => $kvVersion,
        ];
    }

    /**
     * @param  array<int, array{id: string, token: string, path: string, url: string, kv_engine_version: int}>  $sources
     * @return array<string, mixed>
     */
    protected function requestVaultSources(array $sources): array
    {
        if (count($sources) > 1 && $this->booleanConfig('vault.http.concurrent', true) && class_exists(\Illuminate\Http\Client\Pool::class)) {
            return $this->requestVaultSourcesConcurrently($sources);
        }

        $responses = [];

        foreach ($sources as $source) {
            $responses[$source['id']] = $this->requestVaultSourceWithRetries($source);
        }

        return $responses;
    }

    /**
     * @param  array<int, array{id: string, token: string, path: string, url: string, kv_engine_version: int}>  $sources
     * @return array<string, mixed>
     */
    protected function requestVaultSourcesConcurrently(array $sources): array
    {
        $attempts = $this->positiveInt('vault.http.retries', 3);
        $maxConcurrency = $this->positiveInt('vault.http.max_concurrency', 5);
        $pending = $sources;
        $responses = [];

        for ($attempt = 1; $attempt <= $attempts && $pending !== []; $attempt++) {
            if ($attempt > 1) {
                $this->sleepForRetry($attempt - 1);
            }

            $nextPending = [];

            foreach (array_chunk($pending, $maxConcurrency) as $batch) {
                try {
                    $batchResponses = Http::pool(function ($pool) use ($batch) {
                        $requests = [];

                        foreach ($batch as $source) {
                            $requests[] = $pool->as($source['id'])
                                ->withHeaders([
                                    'Accept' => 'application/json',
                                    'X-Vault-Token' => $source['token'],
                                ])
                                ->withOptions($this->httpRequestOptions())
                                ->get($source['url']);
                        }

                        return $requests;
                    });
                } catch (Throwable $e) {
                    Log::warning('VaultPackage: Concurrent Vault requests were unavailable; falling back to sequential requests. ' . $e->getMessage());

                    foreach ($batch as $source) {
                        $responses[$source['id']] = $this->requestVaultSourceWithRetries($source);
                    }

                    continue;
                }

                foreach ($batch as $source) {
                    $outcome = $batchResponses[$source['id']] ?? new \RuntimeException('No response was returned for this Vault source.');

                    if ($this->isSuccessfulResponse($outcome) || $attempt === $attempts || !$this->isRetryableOutcome($outcome)) {
                        $responses[$source['id']] = $outcome;

                        continue;
                    }

                    $nextPending[] = $source;
                }
            }

            $pending = $nextPending;
        }

        return $responses;
    }

    /**
     * @param  array{id: string, token: string, path: string, url: string, kv_engine_version: int}  $source
     * @return mixed
     */
    protected function requestVaultSourceWithRetries(array $source)
    {
        $attempts = $this->positiveInt('vault.http.retries', 3);
        $outcome = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $outcome = Http::withHeaders([
                    'Accept' => 'application/json',
                    'X-Vault-Token' => $source['token'],
                ])->withOptions($this->httpRequestOptions())->get($source['url']);
            } catch (Throwable $e) {
                $outcome = $e;
            }

            if ($this->isSuccessfulResponse($outcome) || $attempt === $attempts || !$this->isRetryableOutcome($outcome)) {
                return $outcome;
            }

            $this->sleepForRetry($attempt);
        }

        return $outcome;
    }

    /**
     * @return array<string, float>
     */
    protected function httpRequestOptions(): array
    {
        return [
            // Compatible with Laravel 8, where connectTimeout() is unavailable.
            'connect_timeout' => (float) $this->positiveInt('vault.http.connect_timeout', 2),
            'timeout' => (float) $this->positiveInt('vault.http.timeout', 5),
        ];
    }

    /**
     * @param  mixed  $outcome
     */
    protected function isSuccessfulResponse($outcome): bool
    {
        return is_object($outcome) && method_exists($outcome, 'successful') && $outcome->successful();
    }

    /**
     * @param  mixed  $outcome
     */
    protected function isRetryableOutcome($outcome): bool
    {
        if ($outcome instanceof Throwable) {
            return true;
        }

        if (!is_object($outcome) || !method_exists($outcome, 'status')) {
            return false;
        }

        return in_array((int) $outcome->status(), [408, 429, 500, 502, 503, 504], true);
    }

    protected function sleepForRetry(int $retryNumber): void
    {
        $backoff = config('vault.http.retry_backoff_ms', [250, 500, 1000]);
        $backoff = is_array($backoff) && $backoff !== [] ? array_values($backoff) : [250, 500, 1000];
        $index = min(max(0, $retryNumber - 1), count($backoff) - 1);
        $delay = max(0, (int) $backoff[$index]);
        $jitter = $this->nonNegativeInt('vault.http.retry_jitter_ms', 100);

        if ($jitter > 0) {
            $delay += random_int(0, $jitter);
        }

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    /**
     * @param  mixed  $response
     * @param  array{id: string, token: string, path: string, url: string, kv_engine_version: int}  $source
     * @return array<string, mixed>|null
     */
    protected function extractVaultPayload($response, array $source): ?array
    {
        try {
            $body = $response->json();
        } catch (Throwable $e) {
            Log::warning("VaultPackage: Invalid JSON response from Vault for path {$source['path']}: {$e->getMessage()}");

            return null;
        }

        if (!is_array($body)) {
            Log::warning("VaultPackage: Vault response for path {$source['path']} was not a JSON object.");

            return null;
        }

        $payload = $source['kv_engine_version'] === 1
            ? ($body['data'] ?? null)
            : ($body['data']['data'] ?? null);

        if (!is_array($payload)) {
            Log::warning("VaultPackage: Vault returned an invalid KV v{$source['kv_engine_version']} payload for path {$source['path']}.");

            return null;
        }

        return $payload;
    }

    /**
     * @param  array{id: string, token: string, path: string, url: string, kv_engine_version: int}  $source
     * @param  mixed  $outcome
     */
    protected function logVaultRequestFailure(array $source, $outcome): void
    {
        if ($outcome instanceof Throwable) {
            Log::error("VaultPackage: Vault call error for path {$source['path']} - {$outcome->getMessage()}");

            return;
        }

        $status = is_object($outcome) && method_exists($outcome, 'status') ? $outcome->status() : 'unknown';
        Log::warning("VaultPackage: Empty or failed response from Vault for path {$source['path']} (HTTP {$status}).");
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @param  array<string, mixed>  $sourceSecrets
     * @param  array<string, true>  $neededLookup
     */
    protected function mergeRequestedSecrets(array &$secrets, array $sourceSecrets, array $neededLookup): void
    {
        foreach ($sourceSecrets as $key => $value) {
            $key = (string) $key;

            if ($neededLookup !== [] && !array_key_exists($key, $neededLookup)) {
                continue;
            }

            $secrets[$key] = $value;
        }
    }

    /**
     * @param  array<int, string>  $neededKeys
     * @return array<string, true>
     */
    protected function neededKeyLookup(array $neededKeys): array
    {
        $lookup = [];

        foreach ($neededKeys as $key) {
            $lookup[$key] = true;
        }

        return $lookup;
    }

    /**
     * @param  array<int, string>|null  $onlyVaultKeys
     * @return array<string, string>
     */
    protected function mappedSecrets(?array $onlyVaultKeys = null): array
    {
        $configuredMap = config('vault.map', []);

        if (!is_array($configuredMap)) {
            Log::error('VaultPackage: The vault.map configuration must be an array.');

            return [];
        }

        $onlyLookup = $onlyVaultKeys === null ? null : $this->neededKeyLookup($onlyVaultKeys);
        $map = [];

        foreach ($configuredMap as $configKey => $vaultKey) {
            if (!is_string($configKey) || !is_scalar($vaultKey) || trim((string) $vaultKey) === '') {
                Log::warning('VaultPackage: Ignoring an invalid vault.map entry.');

                continue;
            }

            $vaultKey = (string) $vaultKey;

            if ($onlyLookup !== null && !array_key_exists($vaultKey, $onlyLookup)) {
                continue;
            }

            $map[$configKey] = $vaultKey;
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<int, string>
     */
    protected function uniqueVaultKeys(array $map): array
    {
        $unique = [];

        foreach ($map as $vaultKey) {
            $unique[$vaultKey] = $vaultKey;
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, string>  $vaultKeys
     * @param  array<string, array<string, mixed>|null>  $entries
     * @return array<int, string>
     */
    protected function keysNeedingLoad(array $vaultKeys, array $entries, bool $force): array
    {
        if ($force) {
            return $vaultKeys;
        }

        return array_values(array_filter($vaultKeys, function (string $vaultKey) use ($entries): bool {
            return !is_array($entries[$vaultKey] ?? null);
        }));
    }

    /**
     * @param  array<int, string>  $vaultKeys
     * @return array<string, array<string, mixed>|null>
     */
    protected function readCacheEntries(array $vaultKeys): array
    {
        $cacheKeys = [];

        foreach ($vaultKeys as $vaultKey) {
            $cacheKeys[$vaultKey] = $this->cacheKey($vaultKey);
        }

        try {
            $cached = $this->cache()->many(array_values($cacheKeys));
        } catch (Throwable $e) {
            Log::error('VaultPackage: Unable to read the configured cache store. ' . $e->getMessage());
            $cached = [];
        }

        $entries = [];

        foreach ($cacheKeys as $vaultKey => $cacheKey) {
            $entry = is_array($cached) && array_key_exists($cacheKey, $cached) ? $cached[$cacheKey] : null;
            $entries[$vaultKey] = $this->isCacheEntry($entry) ? $entry : null;
        }

        return $entries;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    protected function putCacheEntries(array $entries, int $ttl): void
    {
        if ($entries === []) {
            return;
        }

        try {
            $this->cache()->putMany($entries, $ttl);
        } catch (Throwable $e) {
            Log::error('VaultPackage: Unable to write the configured cache store. ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function valueCacheEntry($value): array
    {
        return [
            self::CACHE_ENTRY_MARKER => 1,
            'state' => 'value',
            'value' => $value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function missingCacheEntry(): array
    {
        return [
            self::CACHE_ENTRY_MARKER => 1,
            'state' => 'missing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function unavailableCacheEntry(): array
    {
        return [
            self::CACHE_ENTRY_MARKER => 1,
            'state' => 'unavailable',
        ];
    }

    /**
     * @param  mixed  $entry
     */
    protected function isCacheEntry($entry): bool
    {
        if (!is_array($entry)
            || ($entry[self::CACHE_ENTRY_MARKER] ?? null) !== 1
            || !isset($entry['state'])
            || !in_array($entry['state'], ['value', 'missing', 'unavailable'], true)) {
            return false;
        }

        return $entry['state'] !== 'value' || array_key_exists('value', $entry);
    }

    /**
     * @param  mixed  $entry
     */
    protected function hasValueCacheEntry($entry): bool
    {
        return $this->isCacheEntry($entry) && $entry['state'] === 'value';
    }

    /**
     * @param  mixed  $entry
     * @return mixed
     */
    protected function cacheEntryValue($entry)
    {
        if (!$this->hasValueCacheEntry($entry) || !array_key_exists('value', $entry)) {
            return null;
        }

        return $entry['value'];
    }

    protected function cache()
    {
        $store = config('vault.cache_store');

        return is_string($store) && trim($store) !== '' ? Cache::store($store) : Cache::store();
    }

    /**
     * @param  mixed  $cache
     */
    protected function cacheSupportsLocks($cache): bool
    {
        try {
            return is_object($cache) && method_exists($cache, 'getStore') && method_exists($cache->getStore(), 'lock');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Keep the source-load lock alive for at least the longest request plan the
     * current configuration can create. A pool can fall back to sequential
     * requests, so the sequential upper bound is part of the calculation.
     */
    protected function sourceLoadLockTtl(): int
    {
        $configured = $this->positiveInt('vault.lock_ttl', 60);

        return max($configured, $this->estimatedSourceLoadSeconds());
    }

    protected function estimatedSourceLoadSeconds(): int
    {
        if (config('vault.mode', 'file') !== 'token') {
            return 0;
        }

        $sources = config('vault.token_sources', []);
        $sourceCount = is_array($sources) ? count($sources) : 0;

        if ($sourceCount === 0) {
            return 0;
        }

        $attempts = $this->positiveInt('vault.http.retries', 3);
        $timeoutMilliseconds = $this->positiveInt('vault.http.timeout', 5) * 1000;
        $retryDelayMilliseconds = $this->maximumRetryDelayMilliseconds($attempts);

        // A sequential fallback retries every source independently.
        $sequentialMilliseconds = $sourceCount * (($attempts * $timeoutMilliseconds) + $retryDelayMilliseconds);
        $worstCaseMilliseconds = $sequentialMilliseconds;

        if ($sourceCount > 1
            && $this->booleanConfig('vault.http.concurrent', true)
            && class_exists(\Illuminate\Http\Client\Pool::class)) {
            $chunks = (int) ceil($sourceCount / $this->positiveInt('vault.http.max_concurrency', 5));

            // In the pooled path a retry round is shared by all sources, but
            // each bounded chunk can still consume the full request timeout.
            $pooledMilliseconds = ($chunks * $attempts * $timeoutMilliseconds) + $retryDelayMilliseconds;
            $worstCaseMilliseconds = max($worstCaseMilliseconds, $pooledMilliseconds);
        }

        // Leave a small allowance for request dispatch, cache I/O, and lock
        // release after the last source response.
        return (int) ceil($worstCaseMilliseconds / 1000) + 5;
    }

    protected function maximumRetryDelayMilliseconds(int $attempts): int
    {
        $backoff = config('vault.http.retry_backoff_ms', [250, 500, 1000]);
        $backoff = is_array($backoff) && $backoff !== [] ? array_values($backoff) : [250, 500, 1000];
        $jitter = $this->nonNegativeInt('vault.http.retry_jitter_ms', 100);
        $delay = 0;

        for ($retryNumber = 1; $retryNumber < $attempts; $retryNumber++) {
            $index = min($retryNumber - 1, count($backoff) - 1);
            $delay += max(0, (int) $backoff[$index]) + $jitter;
        }

        return $delay;
    }

    protected function cacheKey(string $vaultKey): string
    {
        return $this->cachePrefix() . ':secret:' . $this->cacheScope() . ':' . hash('sha256', $vaultKey);
    }

    protected function cacheLockKey(): string
    {
        return $this->cachePrefix() . ':source-load-lock:' . $this->cacheScope();
    }

    protected function fileFingerprintCacheKey(): string
    {
        return $this->cachePrefix() . ':file-fingerprint:' . $this->cacheScope();
    }

    protected function cachePrefix(): string
    {
        $prefix = trim((string) config('vault.cache_prefix', 'vault'), ':');

        return $prefix === '' ? 'vault' : $prefix;
    }

    protected function cacheScope(): string
    {
        $mode = (string) config('vault.mode', 'file');

        if ($mode === 'file') {
            $configuredPaths = config('vault.file_paths', []);
            $sources = array_map(function ($path) {
                if (is_string($path)) {
                    return $this->resolvePath($path);
                }

                return is_scalar($path) || $path === null ? (string) $path : get_debug_type($path);
            }, is_array($configuredPaths) ? $configuredPaths : []);
        } else {
            $sources = [];
            $configuredSources = config('vault.token_sources', []);

            foreach (is_array($configuredSources) ? $configuredSources : [] as $source) {
                $sources[] = is_array($source) ? [
                    'url' => $this->sourceIdentityValue($source['url'] ?? null),
                    'path' => $this->sourceIdentityValue($source['path'] ?? null),
                    'kv_engine_version' => $this->sourceIdentityValue($source['kv_engine_version'] ?? 2),
                ] : null;
            }
        }

        $identity = [
            'cache_version' => config('vault.cache_version', 2),
            'mode' => $mode,
            'sources' => $sources,
        ];

        $serializedIdentity = serialize($identity);

        if ($this->cacheScope !== null && $this->cacheScopeIdentity === $serializedIdentity) {
            return $this->cacheScope;
        }

        $this->cacheScopeIdentity = $serializedIdentity;
        $this->cacheScope = substr(hash('sha256', $serializedIdentity), 0, 24);

        return $this->cacheScope;
    }

    /**
     * Convert invalid source configuration into a stable identity marker rather
     * than allowing it to make cache-key generation fail before validation can
     * log the actual configuration problem.
     *
     * @param  mixed  $value
     * @return bool|float|int|string|null
     */
    protected function sourceIdentityValue($value)
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return get_debug_type($value);
    }

    protected function fileSourceHasChanged(): bool
    {
        if (!$this->fileFingerprintingEnabled()) {
            return false;
        }

        $fingerprint = $this->currentFileFingerprint();

        if ($fingerprint === null) {
            return false;
        }

        try {
            return $this->cache()->get($this->fileFingerprintCacheKey()) !== $fingerprint;
        } catch (Throwable $e) {
            Log::warning('VaultPackage: Unable to read the file fingerprint cache entry. ' . $e->getMessage());

            return false;
        }
    }

    protected function storeFileFingerprint(string $fingerprint): void
    {
        if (!$this->fileFingerprintingEnabled()) {
            return;
        }

        try {
            $this->cache()->forever($this->fileFingerprintCacheKey(), $fingerprint);
        } catch (Throwable $e) {
            Log::warning('VaultPackage: Unable to store the file fingerprint. ' . $e->getMessage());
        }
    }

    protected function fileFingerprintingEnabled(): bool
    {
        $paths = config('vault.file_paths', []);

        return config('vault.mode', 'file') === 'file'
            && is_array($paths)
            && $paths !== []
            && $this->booleanConfig('vault.file_fingerprinting', true);
    }

    protected function currentFileFingerprint(): ?string
    {
        $paths = config('vault.file_paths', []);

        if (!is_array($paths)) {
            return null;
        }

        $parts = [];

        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                $parts[] = ['invalid'];

                continue;
            }

            $resolvedPath = $this->resolvePath($path);
            clearstatcache(true, $resolvedPath);

            if (!is_file($resolvedPath)) {
                $parts[] = [$resolvedPath, 'missing'];

                continue;
            }

            $parts[] = [
                $resolvedPath,
                @filemtime($resolvedPath),
                @filesize($resolvedPath),
            ];
        }

        return hash('sha256', serialize($parts));
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return array{secrets: array<string, mixed>, complete: bool, cacheable_values: bool, file_fingerprint: ?string}
     */
    protected function unsupportedModeResult(): array
    {
        Log::error('VaultPackage: Unsupported vault.mode configuration.');

        return $this->failedLoadResult();
    }

    /**
     * @return array{secrets: array<string, mixed>, complete: bool, cacheable_values: bool, file_fingerprint: ?string}
     */
    protected function failedLoadResult(): array
    {
        return [
            'secrets' => [],
            'complete' => false,
            'cacheable_values' => false,
            'file_fingerprint' => null,
        ];
    }

    protected function positiveInt(string $key, int $default): int
    {
        return max(1, (int) config($key, $default));
    }

    protected function nonNegativeInt(string $key, int $default): int
    {
        return max(0, (int) config($key, $default));
    }

    protected function booleanConfig(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}
