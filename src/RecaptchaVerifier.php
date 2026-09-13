<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

/**
 * Verifies a Google reCAPTCHA v2 or v3 response token against the siteverify API.
 *
 * @package rafalmasiarek\DashboardKitRecaptcha
 */
final class RecaptchaVerifier
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * @param string $secretKey reCAPTCHA secret key from the Google console.
     */
    public function __construct(private readonly string $secretKey)
    {
    }

    /**
     * Verify a g-recaptcha-response token from a form submission.
     *
     * $minScore and $expectedAction only apply to v3 tokens — siteverify's
     * response includes 'score' and 'action' for those; both checks are
     * skipped (treated as passing) when the corresponding field is absent,
     * so v2 tokens are unaffected.
     *
     * @param  string      $token          Value of the g-recaptcha-response POST field.
     * @param  string      $remoteIp       Client IP address for additional validation.
     * @param  float|null  $minScore       v3 only: minimum accepted score. Null = do not check.
     * @param  string|null $expectedAction v3 only: expected 'action' value. Null = do not check.
     * @return bool                        True when Google confirms the token is valid.
     */
    public function verify(
        string $token,
        string $remoteIp = '',
        ?float $minScore = null,
        ?string $expectedAction = null,
    ): bool {
        if ($token === '') {
            return false;
        }

        $payload = \http_build_query([
            'secret'   => $this->secretKey,
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        $ctx = \stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);

        $raw = @\file_get_contents(self::VERIFY_URL, false, $ctx);
        if ($raw === false) {
            return false;
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data) || ($data['success'] ?? false) !== true) {
            return false;
        }

        if ($minScore !== null && isset($data['score']) && (float) $data['score'] < $minScore) {
            return false;
        }

        if ($expectedAction !== null && isset($data['action']) && (string) $data['action'] !== $expectedAction) {
            return false;
        }

        return true;
    }
}
