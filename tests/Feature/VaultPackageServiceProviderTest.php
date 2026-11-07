<?php

namespace VaultImplementation\VaultPackage\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use VaultImplementation\VaultPackage\Tests\TestCase;
use VaultImplementation\VaultPackage\VaultPackageServiceProvider;

class VaultPackageServiceProviderTest extends TestCase
{
    public function test_a_cold_batch_loads_each_token_source_once_and_a_warm_batch_loads_none(): void
    {
        $this->configureTokenVault([
            [
                'token' => 'application-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/application',
                'kv_engine_version' => 2,
            ],
            [
                'token' => 'billing-token',
                'url' => 'https://vault.test/',
                'path' => 'v1/secret/data/billing',
                'kv_engine_version' => 2,
            ],
        ], [
            'services.application.first' => 'FIRST',
            'services.application.second' => 'SECOND',
            'services.application.shared' => 'SHARED',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://vault.test/v1/secret/data/application') {
                return Http::response([
                    'data' => ['data' => [
                        'FIRST' => 'one',
                        'SECOND' => 'two',
                        'SHARED' => 'from-application',
                    ]],
                ]);
            }

            return Http::response([
                'data' => ['data' => [
                    'UNMAPPED' => 'discarded',
                    'SHARED' => 'from-billing',
                ]],
            ]);
        });

        $this->vault()->sync();

        $this->assertSame('one', config('services.application.first'));
        $this->assertSame('two', config('services.application.second'));
        $this->assertSame('from-billing', config('services.application.shared'));
        Http::assertSentCount(2);

        $this->vault()->sync();

        Http::assertSentCount(2);
    }

    public function test_missing_keys_are_negative_cached_without_another_source_load(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.missing' => 'MISSING',
        ]);

        Http::fake([
            '*' => Http::response(['data' => ['data' => []]]),
        ]);

        $this->vault()->sync();
        $this->vault()->sync();

        $this->assertNull(config('services.application.missing'));
        Http::assertSentCount(1);
    }

    public function test_adding_one_mapping_loads_the_new_key_without_replacing_warm_keys(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.a' => 'A',
        ]);

        $generation = 1;
        Http::fake(function () use (&$generation) {
            return Http::response(['data' => ['data' => [
                'A' => 'a-' . $generation,
                'B' => 'b-' . $generation,
            ]]]);
        });

        $this->vault()->sync();

        config(['vault.map' => [
            'services.application.a' => 'A',
            'services.application.b' => 'B',
        ]]);
        $generation = 2;
        $this->vault()->sync();

        $this->assertSame('a-1', config('services.application.a'));
        $this->assertSame('b-2', config('services.application.b'));
        Http::assertSentCount(2);
    }

    public function test_changing_a_source_creates_a_new_cache_scope(): void
    {
        $oldSource = $this->tokenSource();
        $this->configureTokenVault([$oldSource], [
            'services.application.a' => 'A',
        ]);

        Http::fake(function (Request $request) {
            return str_contains($request->url(), 'new-vault.test')
                ? Http::response(['data' => ['data' => ['A' => 'new-source']]])
                : Http::response(['data' => ['data' => ['A' => 'old-source']]]);
        });

        $this->vault()->sync();

        $newSource = $oldSource;
        $newSource['url'] = 'https://new-vault.test';
        config(['vault.token_sources' => [$newSource]]);
        $this->vault()->sync();

        $this->assertSame('new-source', config('services.application.a'));
        Http::assertSentCount(2);
    }

    public function test_source_load_lock_lease_covers_a_multi_chunk_sequential_fallback(): void
    {
        $this->configureTokenVault(array_fill(0, 20, $this->tokenSource()), []);
        config([
            'vault.lock_ttl' => 60,
            'vault.http.concurrent' => true,
            'vault.http.max_concurrency' => 5,
            'vault.http.timeout' => 5,
            'vault.http.retries' => 3,
            'vault.http.retry_backoff_ms' => [250, 500, 1000],
            'vault.http.retry_jitter_ms' => 100,
        ]);

        $lease = (new LockDurationProvider($this->app))->calculatedLockTtl();

        // 20 sources × (3 × 5 seconds + max 950 ms retry delay), plus the
        // package's five-second release allowance.
        $this->assertGreaterThanOrEqual(324, $lease);
    }

    public function test_lock_contention_does_not_start_an_unlocked_duplicate_source_sweep(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.a' => 'A',
        ]);
        config(['vault.lock_wait_seconds' => 0]);

        $provider = new LockContentionProvider($this->app);
        $lock = Cache::lock($provider->sourceLockKeyForTest(), 60);
        $this->assertTrue($lock->get());

        try {
            Http::fake();
            $provider->sync();

            $this->assertNull(config('services.application.a'));
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_kv_v1_and_kv_v2_sources_are_normalized(): void
    {
        $this->configureTokenVault([
            [
                'token' => 'v1-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/application',
                'kv_engine_version' => 1,
            ],
            [
                'token' => 'v2-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/billing',
                'kv_engine_version' => 2,
            ],
        ], [
            'services.application.v1' => 'V1_SECRET',
            'services.application.v2' => 'V2_SECRET',
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://vault.test/v1/secret/application') {
                return Http::response(['data' => ['V1_SECRET' => 'v1-value']]);
            }

            return Http::response(['data' => ['data' => ['V2_SECRET' => 'v2-value']]]);
        });

        $this->vault()->sync();

        $this->assertSame('v1-value', config('services.application.v1'));
        $this->assertSame('v2-value', config('services.application.v2'));
        Http::assertSentCount(2);
    }

    public function test_independent_sources_can_use_the_concurrent_request_path(): void
    {
        $this->configureTokenVault([
            [
                'token' => 'first-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/first',
                'kv_engine_version' => 2,
            ],
            [
                'token' => 'second-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/second',
                'kv_engine_version' => 2,
            ],
        ], [
            'services.application.first' => 'FIRST',
            'services.application.second' => 'SECOND',
        ]);
        config(['vault.http.concurrent' => true]);

        Http::fake(function (Request $request) {
            return str_ends_with($request->url(), '/first')
                ? Http::response(['data' => ['data' => ['FIRST' => 'one']]])
                : Http::response(['data' => ['data' => ['SECOND' => 'two']]]);
        });

        $this->vault()->sync();

        $this->assertSame('one', config('services.application.first'));
        $this->assertSame('two', config('services.application.second'));
        Http::assertSentCount(2);
    }

    public function test_retryable_failures_are_retried_but_client_errors_are_not(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.a' => 'A',
        ]);
        config([
            'vault.http.retries' => 2,
            'vault.http.retry_backoff_ms' => [0],
            'vault.http.retry_jitter_ms' => 0,
        ]);

        $attempt = 0;
        $returnClientError = false;
        Http::fake(function () use (&$attempt, &$returnClientError) {
            $attempt++;

            if ($returnClientError) {
                return Http::response([], 401);
            }

            return $attempt === 1
                ? Http::response([], 503)
                : Http::response(['data' => ['data' => ['A' => 'recovered']]]);
        });

        $this->vault()->sync();

        $this->assertSame('recovered', config('services.application.a'));
        Http::assertSentCount(2);

        $returnClientError = true;
        $this->vault()->refresh();

        $this->assertSame('recovered', config('services.application.a'));
        Http::assertSentCount(3);
    }

    public function test_refreshing_one_key_does_not_replace_other_cached_keys(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.a' => 'A',
            'services.application.b' => 'B',
        ]);

        $generation = 1;
        Http::fake(function () use (&$generation) {
            return Http::response(['data' => ['data' => [
                'A' => 'a-' . $generation,
                'B' => 'b-' . $generation,
            ]]]);
        });

        $this->vault()->sync();
        $generation = 2;
        $this->vault()->refreshKey('A');

        $this->assertSame('a-2', config('services.application.a'));
        $this->assertSame('b-1', config('services.application.b'));
        Http::assertSentCount(2);
    }

    public function test_failed_refresh_keeps_the_last_known_good_values(): void
    {
        $this->configureTokenVault([$this->tokenSource()], [
            'services.application.a' => 'A',
        ]);

        Http::fake([
            '*' => Http::response(['data' => ['data' => ['A' => 'known-good']]]),
        ]);
        $this->vault()->sync();

        Http::fake([
            '*' => Http::response([], 503),
        ]);
        $this->vault()->refresh();

        $this->assertSame('known-good', config('services.application.a'));
        Http::assertSentCount(1);
    }

    public function test_a_partial_source_failure_keeps_values_from_healthy_sources(): void
    {
        $this->configureTokenVault([
            [
                'token' => 'healthy-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/healthy',
                'kv_engine_version' => 2,
            ],
            [
                'token' => 'unavailable-token',
                'url' => 'https://vault.test',
                'path' => '/v1/secret/data/unavailable',
                'kv_engine_version' => 2,
            ],
        ], [
            'services.application.available' => 'AVAILABLE',
            'services.application.unavailable' => 'UNAVAILABLE',
        ]);

        Http::fake(function (Request $request) {
            return str_ends_with($request->url(), '/healthy')
                ? Http::response(['data' => ['data' => ['AVAILABLE' => 'from-healthy-source']]])
                : Http::response([], 503);
        });

        $this->vault()->sync();
        $this->vault()->sync();

        $this->assertSame('from-healthy-source', config('services.application.available'));
        $this->assertNull(config('services.application.unavailable'));
        Http::assertSentCount(2);
    }

    public function test_file_fingerprint_refreshes_warm_entries_after_a_file_rewrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'laravel-vault-test-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, "FILE_SECRET=first\n");
            config([
                'vault.mode' => 'file',
                'vault.file_paths' => [$path],
                'vault.map' => ['services.application.file_secret' => 'FILE_SECRET'],
            ]);

            $this->vault()->sync();
            $this->assertSame('first', config('services.application.file_secret'));

            // Different content length ensures this works on filesystems with
            // coarse mtime precision as well.
            file_put_contents($path, "FILE_SECRET=second-value\n");
            clearstatcache(true, $path);

            $this->vault()->sync();

            $this->assertSame('second-value', config('services.application.file_secret'));
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_an_unstable_file_read_is_not_cached_as_a_secret_value(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'laravel-vault-unstable-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, "FILE_SECRET=value-that-must-not-be-cached\n");
            config([
                'vault.mode' => 'file',
                'vault.file_paths' => [$path],
                'vault.map' => ['services.application.file_secret' => 'FILE_SECRET'],
            ]);

            (new UnstableFileReadProvider($this->app))->sync();

            $this->assertNull(config('services.application.file_secret'));
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_an_empty_map_does_not_make_a_vault_request(): void
    {
        $this->configureTokenVault([$this->tokenSource()], []);
        Http::fake();

        $this->vault()->sync();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenSource(): array
    {
        return [
            'token' => 'test-token',
            'url' => 'https://vault.test',
            'path' => '/v1/secret/data/application',
            'kv_engine_version' => 2,
        ];
    }
}

class UnstableFileReadProvider extends VaultPackageServiceProvider
{
    private int $fingerprintCalls = 0;

    protected function fileSourceHasChanged(): bool
    {
        return false;
    }

    protected function currentFileFingerprint(): ?string
    {
        return ++$this->fingerprintCalls === 1 ? 'before-read' : 'after-read';
    }
}

class LockDurationProvider extends VaultPackageServiceProvider
{
    public function calculatedLockTtl(): int
    {
        return $this->sourceLoadLockTtl();
    }
}

class LockContentionProvider extends VaultPackageServiceProvider
{
    public function sourceLockKeyForTest(): string
    {
        return $this->cacheLockKey();
    }
}
