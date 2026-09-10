<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

/**
 * Verifies a Google reCAPTCHA v2 response token against the siteverify API.
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
     * @param  string $token    Value of the g-recaptcha-response POST field.
     * @param  string $remoteIp Client IP address for additional validation.
     * @return bool             True when Google confirms the token is valid.
     */
    public function verify(string $token, string $remoteIp = ''): bool
    {
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

        return \is_array($data) && ($data['success'] ?? false) === true;
    }
}
