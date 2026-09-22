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
 * Pins that a cryptographically valid solution is accepted once and refused on replay, and
 * that the burned signature always comes from the branch that verified. Sniffing it out of
 * the payload instead let a crafted `challenge` key divert the claim and keep the real token
 * replayable.
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

    public function testPowSolutionBurnsTheChallengeSignature(): void
    {
        $payload = $this->solvedChallengePayload();

        $captcha = new AltchaCaptcha(new NullLogger(), $this->guardExpecting($payload['challenge']['signature']));

        self::assertTrue($captcha->isValid($this->request($payload), $this->config()));
    }

    public function testServerSignatureIgnoresAnInjectedChallengeSignature(): void
    {
        $payload = $this->serverSignaturePayload();
        $payload['challenge'] = ['signature' => 'injected-by-the-client'];

        $captcha = new AltchaCaptcha(new NullLogger(), $this->guardExpecting($payload['signature']));

        self::assertTrue($captcha->isValid($this->request($payload), $this->config()));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function config(): array
    {
        return ['config' => [AltchaCaptcha::CONFIG_FIELD_SECRET => self::SECRET]];
    }

    private function guardExpecting(string $signature): ChallengeReplayGuard
    {
        $guard = $this->createMock(ChallengeReplayGuard::class);
        $guard->expects(self::once())
            ->method('claim')
            ->with($signature)
            ->willReturn(true);

        return $guard;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        return new Request([], [
            AltchaCaptcha::CAPTCHA_REQUEST_PARAMETER => base64_encode((string) json_encode($payload)),
        ]);
    }

    private function requestWithSolvedChallenge(): Request
    {
        return $this->request($this->solvedChallengePayload());
    }

    /**
     * @return array{challenge: array<string, mixed>, solution: array<string, mixed>}
     */
    private function solvedChallengePayload(): array
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

        return [
            'challenge' => $challenge->toArray(),
            'solution' => ['counter' => $solution->counter, 'derivedKey' => $solution->derivedKey],
        ];
    }

    /**
     * Mirrors what an Altcha server (Sentinel) returns for a verified submission.
     *
     * @return array<string, mixed>
     */
    private function serverSignaturePayload(): array
    {
        $verificationData = http_build_query(['verified' => 'true', 'expire' => time() + 120]);
        $signature = bin2hex(hash_hmac('sha256', hash('sha256', $verificationData, true), self::SECRET, true));

        return [
            'algorithm' => 'SHA-256',
            'verificationData' => $verificationData,
            'signature' => $signature,
            'verified' => true,
        ];
    }
}
