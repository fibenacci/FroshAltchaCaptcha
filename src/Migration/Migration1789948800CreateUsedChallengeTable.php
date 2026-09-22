<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1789948800CreateUsedChallengeTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789948800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `frosh_altcha_captcha_used_challenge` (
                `signature` VARCHAR(128) NOT NULL,
                `expires_at` DATETIME(3) NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`signature`),
                KEY `idx.frosh_altcha_captcha_used_challenge.expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }
}
