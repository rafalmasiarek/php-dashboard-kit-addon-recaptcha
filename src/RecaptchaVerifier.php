<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

use rafalmasiarek\DashboardKit\Http\HttpClientInterface;

/**
 * Verifies a Google reCAPTCHA v2 or v3 response token against the siteverify API.
 *
 * @package rafalmasiarek\DashboardKitRecaptcha
 */
final class RecaptchaVerifier
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * @param HttpClientInterface $http      Client used to call the siteverify API.
     * @param string              $secretKey reCAPTCHA secret key from the Google console.
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $secretKey,
    ) {
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

        $response = $this->http->request('POST', self::VERIFY_URL, [
            'body' => [
                'secret'   => $this->secretKey,
                'response' => $token,
                'remoteip' => $remoteIp,
            ],
            'timeout' => 5.0,
        ]);

        if ($response->error !== null || $response->body === '') {
            return false;
        }

        $data = \json_decode($response->body, true);
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
