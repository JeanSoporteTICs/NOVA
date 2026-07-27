<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");
        $isIsolatedSqlite = $driver === 'sqlite' && $database === ':memory:';
        $isNamedTestDatabase = preg_match('/(?:^|[_-])test(?:ing)?$/i', $database) === 1;

        if (!$isIsolatedSqlite && !$isNamedTestDatabase) {
            throw new LogicException(
                "Pruebas bloqueadas: la base '{$database}' no es una base aislada de testing."
            );
        }
    }
}
