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
use DateTimeInterface;
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
            if (isset($payload['verificationData'])) {
                if (!ServerSignature::verifyServerSignature($payload, $secretKey)->verified) {
                    return false;
                }

                return $this->claimSingleUse($payload);
            }

            if (isset($payload['challenge'], $payload['solution'])) {
                if (!$this->verifyPowSolution($payload, $secretKey)) {
                    return false;
                }

                return $this->claimSingleUse($payload);
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
     * Rejects a solved challenge that has already been used. Altcha proves the work but
     * not that the token is fresh; without this a single solve is replayable until expiry.
     *
     * @param array<string, mixed> $payload
     */
    private function claimSingleUse(array $payload): bool
    {
        $claim = $this->extractClaim($payload);
        if ($claim === null) {
            return true;
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
     * @return array{signature: string, expiresAt: DateTimeInterface}|null
     */
    private function extractClaim(array $payload): ?array
    {
        $challenge = $payload['challenge'] ?? null;
        if (\is_array($challenge)) {
            $signature = $challenge['signature'] ?? null;
            $expiresAt = $challenge['parameters']['expiresAt'] ?? null;

            if (\is_string($signature) && $signature !== '') {
                return [
                    'signature' => $signature,
                    'expiresAt' => $this->expiryFromTimestamp(\is_int($expiresAt) ? $expiresAt : null),
                ];
            }
        }

        $signature = $payload['signature'] ?? null;
        if (\is_string($signature) && $signature !== '') {
            $expiresAt = null;
            $verificationData = $payload['verificationData'] ?? null;
            if (\is_string($verificationData)) {
                \parse_str($verificationData, $fields);
                $expire = $fields['expire'] ?? null;
                $expiresAt = \is_numeric($expire) ? (int) $expire : null;
            }

            return [
                'signature' => $signature,
                'expiresAt' => $this->expiryFromTimestamp($expiresAt),
            ];
        }

        return null;
    }

    private function expiryFromTimestamp(?int $timestamp): DateTimeImmutable
    {
        // A challenge without an expiry still gets a short retention so the row is
        // deduplicated and later purged rather than kept forever.
        $timestamp ??= \time() + 300;

        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
}
