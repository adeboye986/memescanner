<?php

namespace Tests\Unit;

use App\Services\DatabaseLockRetryService;
use PDOException;
use Tests\TestCase;

class DatabaseLockRetryServiceTest extends TestCase
{
    public function test_retry_succeeds_when_a_transient_sqlite_lock_clears(): void
    {
        config()->set('services.trading.sqlite_lock_retries', 3);
        config()->set('services.trading.sqlite_lock_backoff_ms', 0);
        $attempts = 0;
        $retries = [];

        $result = app(DatabaseLockRetryService::class)->run(
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts < 3) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                }

                return 'persisted';
            },
            function (int $retry, int $maximum) use (&$retries): void {
                $retries[] = [$retry, $maximum];
            },
        );

        $this->assertSame('persisted', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame([[1, 3], [2, 3]], $retries);
    }

    public function test_retries_are_bounded_and_the_lock_is_rethrown_safely(): void
    {
        config()->set('services.trading.sqlite_lock_retries', 3);
        config()->set('services.trading.sqlite_lock_backoff_ms', 0);
        $attempts = 0;
        $retries = [];

        try {
            app(DatabaseLockRetryService::class)->run(
                function () use (&$attempts): never {
                    $attempts++;

                    throw new PDOException('database is locked');
                },
                function (int $retry, int $maximum) use (&$retries): void {
                    $retries[] = [$retry, $maximum];
                },
            );

            $this->fail('The exhausted database lock should be rethrown.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('database is locked', $exception->getMessage());
        }

        $this->assertSame(4, $attempts);
        $this->assertSame([[1, 3], [2, 3], [3, 3]], $retries);
    }

    public function test_non_lock_failures_are_never_retried(): void
    {
        $attempts = 0;

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('disk I/O error');

        try {
            app(DatabaseLockRetryService::class)->run(
                function () use (&$attempts): never {
                    $attempts++;

                    throw new PDOException('disk I/O error');
                },
                fn (): null => null,
            );
        } finally {
            $this->assertSame(1, $attempts);
        }
    }
}
