<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Storefront\Framework;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Makes a solved Altcha challenge usable exactly once. Altcha verifies the signature, the
 * proof of work and the expiry, but not reuse.
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
     * Returns false when the signature was already claimed. The primary key decides the
     * race when two submissions carry the same token concurrently.
     */
    public function claim(string $signature, \DateTimeInterface $expiresAt): bool
    {
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO `frosh_altcha_captcha_used_challenge` (`signature`, `expires_at`, `created_at`)
                    VALUES (:signature, :expiresAt, :now)
                    SQL,
                [
                    'signature' => $signature,
                    'expiresAt' => $expiresAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (\Throwable $throwable) {
            // Fail open: a storage outage must not lock every protected form. The token is
            // still proof-of-work verified and short-lived, but keep the gap visible.
            $this->logger->error('Altcha replay guard could not record a challenge; allowing it.', [
                'exception' => $throwable->getMessage(),
            ]);
        }

        return true;
    }

    public function purgeExpired(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM `frosh_altcha_captcha_used_challenge` WHERE `expires_at` < :now',
            ['now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT)]
        );
    }
}
