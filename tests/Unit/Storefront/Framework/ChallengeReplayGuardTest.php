<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Tests\Unit\Storefront\Framework;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Frosh\AltchaCaptcha\Storefront\Framework\ChallengeReplayGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the single-use guarantee for a solved Altcha challenge. A regression here reopens
 * token replay: one solved proof-of-work would again pass an unlimited number of
 * submissions until it expires.
 */
final class ChallengeReplayGuardTest extends TestCase
{
    public function testFirstUseIsClaimed(): void
    {
        $guard = new ChallengeReplayGuard($this->connection(null), $this->createMock(LoggerInterface::class));

        self::assertTrue($guard->claim('signature-1', new DateTimeImmutable('+2 minutes')));
    }

    public function testReplayIsRejected(): void
    {
        $violation = (new \ReflectionClass(UniqueConstraintViolationException::class))->newInstanceWithoutConstructor();
        $guard = new ChallengeReplayGuard($this->connection($violation), $this->createMock(LoggerInterface::class));

        self::assertFalse($guard->claim('signature-1', new DateTimeImmutable('+2 minutes')));
    }

    public function testStorageFailureFailsOpenAndLogs(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $guard = new ChallengeReplayGuard($this->connection(new \RuntimeException('db down')), $logger);

        self::assertTrue($guard->claim('signature-1', new DateTimeImmutable('+2 minutes')));
    }

    public function testEmptySignatureSkipsStorage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $guard = new ChallengeReplayGuard($connection, $this->createMock(LoggerInterface::class));

        self::assertTrue($guard->claim('', new DateTimeImmutable('+2 minutes')));
    }

    private function connection(?\Throwable $insertThrows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use ($insertThrows): int {
                if ($insertThrows !== null && str_contains($sql, 'INSERT')) {
                    throw $insertThrows;
                }

                return 1;
            }
        );

        return $connection;
    }
}
