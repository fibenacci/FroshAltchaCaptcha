<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CleanupUsedChallengesTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'frosh_altcha_captcha.cleanup_used_challenges';
    }

    public static function getDefaultInterval(): int
    {
        return self::HOURLY;
    }
}
