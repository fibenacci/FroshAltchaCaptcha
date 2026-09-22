<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Service;

use Frosh\AltchaCaptcha\Storefront\Framework\AltchaCaptcha;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Reads the tunable challenge parameters. Both are clamped: the stored values are free-form
 * JSON in system_config, and an out-of-range one either expires every form instantly or
 * reduces the proof of work to nothing.
 */
class ChallengeSettings
{
    public const CONFIG_FIELD_TTL = 'challengeTtl';
    public const CONFIG_FIELD_COST = 'cost';

    public const DEFAULT_TTL = 300;
    public const DEFAULT_COST = 5000;

    private const MIN_TTL = 30;
    private const MAX_TTL = 3600;
    private const MIN_COST = 1000;
    private const MAX_COST = 200000;

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function ttlSeconds(): int
    {
        return $this->read(self::CONFIG_FIELD_TTL, self::DEFAULT_TTL, self::MIN_TTL, self::MAX_TTL);
    }

    public function cost(): int
    {
        return $this->read(self::CONFIG_FIELD_COST, self::DEFAULT_COST, self::MIN_COST, self::MAX_COST);
    }

    private function read(string $field, int $default, int $min, int $max): int
    {
        $value = $this->systemConfigService->get(AltchaCaptcha::CONFIG_PATH . '.' . $field);

        if (!\is_numeric($value)) {
            return $default;
        }

        return \max($min, \min($max, (int) $value));
    }
}
