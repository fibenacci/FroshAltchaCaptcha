<?php

declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Migration;

use Doctrine\DBAL\Connection;
use Frosh\AltchaCaptcha\Service\ChallengeSettings;
use Frosh\AltchaCaptcha\Storefront\Framework\AltchaCaptcha;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1790035200AddChallengeTuning extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790035200;
    }

    public function update(Connection $connection): void
    {
        $captchaItems = [
            AltchaCaptcha::CAPTCHA_NAME => [
                'config' => [
                    ChallengeSettings::CONFIG_FIELD_TTL => ChallengeSettings::DEFAULT_TTL,
                    ChallengeSettings::CONFIG_FIELD_COST => ChallengeSettings::DEFAULT_COST,
                ],
            ],
        ];

        $sql = <<<'SQL'
            UPDATE system_config
            SET configuration_value = JSON_MERGE_PATCH(configuration_value, :captchaItems)
            WHERE configuration_key = :key
            SQL;

        $connection->executeStatement($sql, [
            'captchaItems' => \json_encode(['_value' => $captchaItems], \JSON_THROW_ON_ERROR),
            'key' => 'core.basicInformation.activeCaptchasV2',
        ]);
    }
}
