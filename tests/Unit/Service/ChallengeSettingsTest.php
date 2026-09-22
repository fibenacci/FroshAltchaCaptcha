<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Tests\Unit\Service;

use Frosh\AltchaCaptcha\Service\ChallengeSettings;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Pins the bounds on the admin-tunable challenge parameters. Out-of-range values are the
 * failure mode that matters: a zero TTL expires every form on arrival, a zero cost hands
 * bots a captcha that costs nothing to solve.
 */
final class ChallengeSettingsTest extends TestCase
{
    public function testDefaultsApplyWhenUnset(): void
    {
        $settings = $this->settings([]);

        self::assertSame(ChallengeSettings::DEFAULT_TTL, $settings->ttlSeconds());
        self::assertSame(ChallengeSettings::DEFAULT_COST, $settings->cost());
    }

    public function testConfiguredValuesAreUsed(): void
    {
        $settings = $this->settings([
            ChallengeSettings::CONFIG_FIELD_TTL => 120,
            ChallengeSettings::CONFIG_FIELD_COST => 20000,
        ]);

        self::assertSame(120, $settings->ttlSeconds());
        self::assertSame(20000, $settings->cost());
    }

    public function testNumericStringsAreAccepted(): void
    {
        $settings = $this->settings([ChallengeSettings::CONFIG_FIELD_TTL => '120']);

        self::assertSame(120, $settings->ttlSeconds());
    }

    public function testNonNumericValuesFallBackToTheDefault(): void
    {
        $settings = $this->settings([ChallengeSettings::CONFIG_FIELD_COST => 'many']);

        self::assertSame(ChallengeSettings::DEFAULT_COST, $settings->cost());
    }

    public function testValuesBelowTheMinimumAreClamped(): void
    {
        $settings = $this->settings([
            ChallengeSettings::CONFIG_FIELD_TTL => 0,
            ChallengeSettings::CONFIG_FIELD_COST => -1,
        ]);

        self::assertSame(30, $settings->ttlSeconds());
        self::assertSame(1000, $settings->cost());
    }

    public function testValuesAboveTheMaximumAreClamped(): void
    {
        $settings = $this->settings([
            ChallengeSettings::CONFIG_FIELD_TTL => 999999,
            ChallengeSettings::CONFIG_FIELD_COST => 999999999,
        ]);

        self::assertSame(3600, $settings->ttlSeconds());
        self::assertSame(200000, $settings->cost());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function settings(array $config): ChallengeSettings
    {
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturnCallback(
            static function (string $key) use ($config): mixed {
                $field = substr($key, (int) strrpos($key, '.') + 1);

                return $config[$field] ?? null;
            }
        );

        return new ChallengeSettings($systemConfigService);
    }
}
