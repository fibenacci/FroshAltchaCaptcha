<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\Storefront\Framework;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\ServerSignature;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Shopware\Storefront\Framework\Captcha\AbstractCaptcha;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

#[AutoconfigureTag(name: 'shopware.storefront.captcha')]
class AltchaCaptcha extends AbstractCaptcha
{
    public const CAPTCHA_NAME = 'altchaCaptcha';
    public const CAPTCHA_REQUEST_PARAMETER = 'altcha';
    public const CONFIG_FIELD_SECRET = 'secretKey';
    public const CONFIG_PATH = 'core.basicInformation.activeCaptchasV2.' . self::CAPTCHA_NAME . '.config';

    private const FALLBACK_RETENTION = 300;

    public function __construct(
        #[Autowire(service: 'monolog.logger.frosh_altcha_captcha')]
        private readonly LoggerInterface $logger,
        private readonly ChallengeReplayGuard $replayGuard,
    ) {
    }

    public function isValid(
        Request $request,
        array $captchaConfig
    ): bool {
        $secretKey = $captchaConfig['config'][self::CONFIG_FIELD_SECRET] ?? '';
        if (!\is_string($secretKey) || $secretKey === '') {
            return false;
        }

        $payload = $this->decodePayload($request->request->getString(self::CAPTCHA_REQUEST_PARAMETER));
        if ($payload === null) {
            return false;
        }

        try {
            // The claim is taken from the branch that verified, never searched across the
            // whole payload: a server-signature payload may carry an unverified `challenge`
            // key, and burning that instead would leave the real token replayable.
            if (isset($payload['verificationData'])) {
                if (!ServerSignature::verifyServerSignature($payload, $secretKey)->verified) {
                    return false;
                }

                return $this->claim($this->serverSignatureClaim($payload));
            }

            if (isset($payload['challenge'], $payload['solution'])) {
                if (!$this->verifyPowSolution($payload, $secretKey)) {
                    return false;
                }

                return $this->claim($this->powClaim($payload));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Altcha captcha verification threw; treating submission as invalid.', [
                'exception' => $e,
            ]);

            return false;
        }

        return false;
    }

    public function getName(): string
    {
        return self::CAPTCHA_NAME;
    }

    /**
     * Decodes the base64-encoded JSON payload sent by the Altcha widget.
     *
     * @return array<string, mixed>|null null when the payload is missing or malformed
     */
    private function decodePayload(string $verifyData): ?array
    {
        if ($verifyData === '') {
            return null;
        }

        $decoded = \base64_decode($verifyData, true);
        if ($decoded === false) {
            return null;
        }

        $payload = \json_decode($decoded, true);

        return \is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyPowSolution(
        array $payload,
        string $secretKey
    ): bool {
        $challengeData = $payload['challenge'];
        $solutionData = $payload['solution'];

        if (!\is_array($challengeData) || !\is_array($solutionData)) {
            return false;
        }

        $challenge = new Challenge(
            ChallengeParameters::fromArray($challengeData['parameters'] ?? []),
            $challengeData['signature'] ?? null,
        );

        $solution = new Solution(
            counter: (int) ($solutionData['counter'] ?? 0),
            derivedKey: (string) ($solutionData['derivedKey'] ?? ''),
        );

        return (new Altcha($secretKey))->verifySolution(new VerifySolutionOptions(
            payload: new Payload($challenge, $solution),
            algorithm: new Pbkdf2(),
        ))->verified;
    }

    /**
     * Altcha proves the work but not that the token is fresh. Without this a single solve
     * passes every submission until it expires.
     *
     * @param array{signature: string, expiresAt: DateTimeImmutable}|null $claim
     */
    private function claim(?array $claim): bool
    {
        if ($claim === null) {
            $this->logger->warning('Altcha captcha verified a payload without a usable signature; rejecting.');

            return false;
        }

        if ($this->replayGuard->claim($claim['signature'], $claim['expiresAt'])) {
            return true;
        }

        $this->logger->warning('Altcha captcha rejected a replayed challenge.');

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{signature: string, expiresAt: DateTimeImmutable}|null
     */
    private function powClaim(array $payload): ?array
    {
        $challenge = $payload['challenge'] ?? null;
        if (!\is_array($challenge)) {
            return null;
        }

        $signature = $challenge['signature'] ?? null;
        if (!\is_string($signature) || $signature === '') {
            return null;
        }

        $expiresAt = $challenge['parameters']['expiresAt'] ?? null;

        return [
            'signature' => $signature,
            'expiresAt' => $this->expiry(\is_int($expiresAt) ? $expiresAt : null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{signature: string, expiresAt: DateTimeImmutable}|null
     */
    private function serverSignatureClaim(array $payload): ?array
    {
        $signature = $payload['signature'] ?? null;
        if (!\is_string($signature) || $signature === '') {
            return null;
        }

        $expiresAt = null;
        $verificationData = $payload['verificationData'] ?? null;
        if (\is_string($verificationData)) {
            \parse_str($verificationData, $fields);
            $expire = $fields['expire'] ?? null;
            $expiresAt = \is_numeric($expire) ? (int) $expire : null;
        }

        return [
            'signature' => $signature,
            'expiresAt' => $this->expiry($expiresAt),
        ];
    }

    private function expiry(?int $timestamp): DateTimeImmutable
    {
        // A challenge without an expiry still gets a retention window so its row is purged.
        $timestamp ??= \time() + self::FALLBACK_RETENTION;

        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
}
