<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Storefront\Framework;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Makes a solved Altcha challenge usable exactly once. Altcha verifies the signature,
 * the proof of work and the expiry, but not reuse — so without this guard a single solve
 * is replayable on every submission until it expires.
 */
class ChallengeReplayGuard
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'monolog.logger.frosh_altcha_captcha')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Claims a challenge signature for its single allowed use. Returns false when the
     * signature was already claimed. The unique key lets the first insert win even when
     * two submissions carry the same token concurrently.
     */
    public function claim(string $signature, DateTimeInterface $expiresAt): bool
    {
        if ($signature === '') {
            return true;
        }

        $this->purgeExpired();

        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO `frosh_altcha_captcha_used_challenge` (`signature`, `expires_at`, `created_at`)
                    VALUES (:signature, :expiresAt, :now)
                    SQL,
                [
                    'signature' => $signature,
                    'expiresAt' => $expiresAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'now' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (\Throwable $throwable) {
            // A storage failure must not lock every protected form; the token is still
            // proof-of-work verified and short-lived. Fail open, but keep the gap visible.
            $this->logger->error('Altcha replay guard could not record a challenge; allowing it.', [
                'exception' => $throwable->getMessage(),
            ]);

            return true;
        }

        return true;
    }

    private function purgeExpired(): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM `frosh_altcha_captcha_used_challenge` WHERE `expires_at` < :now',
                ['now' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
            );
        } catch (\Throwable $throwable) {
            $this->logger->warning('Altcha replay guard could not purge expired challenges.', [
                'exception' => $throwable->getMessage(),
            ]);
        }
    }
}
