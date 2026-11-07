<?php

namespace VaultImplementation\VaultPackage\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase as Orchestra;
use VaultImplementation\VaultPackage\VaultPackageServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [VaultPackageServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('vault.override_env', false);
        $app['config']->set('vault.cache_prefix', 'vault-package-tests');
        $app['config']->set('vault.http.concurrent', false);
        $app['config']->set('vault.http.retry_jitter_ms', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'vault.override_env' => false,
            'vault.cache_prefix' => 'vault-package-tests',
            'vault.cache_store' => null,
            'vault.cache_version' => 2,
            'vault.cache_ttl' => 3600,
            'vault.missing_key_ttl' => 60,
            'vault.failure_ttl' => 15,
            'vault.file_fingerprinting' => true,
            'vault.http.concurrent' => false,
            'vault.http.retries' => 1,
            'vault.http.retry_jitter_ms' => 0,
        ]);

        Cache::flush();
    }

    protected function vault(): VaultPackageServiceProvider
    {
        return $this->app->make('vault.package');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @param  array<string, string>  $map
     */
    protected function configureTokenVault(array $sources, array $map): void
    {
        config([
            'vault.mode' => 'token',
            'vault.token_sources' => $sources,
            'vault.file_paths' => [],
            'vault.map' => $map,
        ]);
    }
}
