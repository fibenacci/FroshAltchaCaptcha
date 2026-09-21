<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Tests\Unit\Storefront\Framework;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\SolveChallengeOptions;
use Frosh\AltchaCaptcha\Storefront\Framework\AltchaCaptcha;
use Frosh\AltchaCaptcha\Storefront\Framework\ChallengeReplayGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pins that a cryptographically valid solution is accepted once and refused on replay:
 * isValid must honour the replay guard's verdict, not only the Altcha verification.
 */
final class AltchaCaptchaTest extends TestCase
{
    private const SECRET = 'test-secret-key';

    public function testValidSolutionPasses(): void
    {
        $guard = $this->createMock(ChallengeReplayGuard::class);
        $guard->expects(self::once())->method('claim')->willReturn(true);

        $captcha = new AltchaCaptcha(new NullLogger(), $guard);

        self::assertTrue($captcha->isValid($this->requestWithSolvedChallenge(), $this->config()));
    }

    public function testReplayedSolutionIsRejected(): void
    {
        $guard = $this->createMock(ChallengeReplayGuard::class);
        $guard->method('claim')->willReturn(false);

        $captcha = new AltchaCaptcha(new NullLogger(), $guard);

        self::assertFalse($captcha->isValid($this->requestWithSolvedChallenge(), $this->config()));
    }

    public function testMissingPayloadIsRejected(): void
    {
        $guard = $this->createMock(ChallengeReplayGuard::class);
        $guard->expects(self::never())->method('claim');

        $captcha = new AltchaCaptcha(new NullLogger(), $guard);

        self::assertFalse($captcha->isValid(new Request(), $this->config()));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function config(): array
    {
        return ['config' => [AltchaCaptcha::CONFIG_FIELD_SECRET => self::SECRET]];
    }

    private function requestWithSolvedChallenge(): Request
    {
        $altcha = new Altcha(self::SECRET);
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 5000,
            counter: 50,
            expiresAt: new \DateTimeImmutable('+2 minutes'),
        ));

        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge,
            algorithm: new Pbkdf2(),
        ));

        self::assertNotNull($solution, 'the seeded challenge must be solvable');

        $payload = [
            'challenge' => $challenge->toArray(),
            'solution' => ['counter' => $solution->counter, 'derivedKey' => $solution->derivedKey],
        ];

        return new Request([], [AltchaCaptcha::CAPTCHA_REQUEST_PARAMETER => base64_encode((string) json_encode($payload))]);
    }
}
