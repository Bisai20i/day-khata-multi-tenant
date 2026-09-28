<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->discardTransactionLeftByAFailedTest();

        parent::setUp();
    }

    /**
     * RefreshDatabase keeps one in-memory SQLite PDO for the whole run. When
     * a test dies mid-request (inside a tenant, or in a Mockery teardown),
     * its transaction can be left open on that PDO, and the next test's
     * migrate:fresh then fails with "cannot VACUUM from within a
     * transaction" - after which every later test errors with "table
     * migrations already exists". No transaction is legitimately open
     * before a test starts, so roll any leftover back and re-migrate from
     * scratch: one failure then stays one failure.
     */
    private function discardTransactionLeftByAFailedTest(): void
    {
        foreach (RefreshDatabaseState::$inMemoryConnections as $pdo) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                RefreshDatabaseState::$migrated = false;
            }
        }
    }
}
