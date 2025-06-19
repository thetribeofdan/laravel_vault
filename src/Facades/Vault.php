<?php

namespace VaultImplementation\VaultPackage\Facades;

use Illuminate\Support\Facades\Facade;

class Vault extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vault.package';
    }
}
