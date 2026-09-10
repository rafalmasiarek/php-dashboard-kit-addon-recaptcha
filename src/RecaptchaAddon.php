<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use Slim\App;

/**
 * Wires Google reCAPTCHA v2 into dashboard-kit login and register forms.
 *
 * @package rafalmasiarek\DashboardKitRecaptcha
 */
final class RecaptchaAddon
{
    /**
     * Register the reCAPTCHA addon with the given application and container.
     *
     * @param App                  $app       Slim application instance.
     * @param ContainerInterface   $container PHP-DI container.
     * @param array<string, mixed> $config    Addon configuration (site_key, secret_key, login, register).
     * @return void
     */
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        if (!\class_exists(\rafalmasiarek\DashboardKit\Dashboard::class)) {
            throw new \LogicException(
                static::class . ' is a dashboard-kit addon and requires rafalmasiarek/dashboard-kit. '
                . 'Run: composer require rafalmasiarek/dashboard-kit'
            );
        }

        $appConfig = $container->has('app.config') ? (array) $container->get('app.config') : [];
        $config    = \array_merge((array) ($appConfig['recaptcha'] ?? []), $config);

        $siteKey   = (string) ($config['site_key']   ?? '');
        $secretKey = (string) ($config['secret_key'] ?? '');

        if ($siteKey === '' || $secretKey === '') {
            throw new \InvalidArgumentException(
                'RecaptchaAddon requires both site_key and secret_key to be set.'
            );
        }

        $verifier = new RecaptchaVerifier($secretKey);
        $tracker  = new FailedLoginTracker();

        $loginConfig    = $config['login']    ?? false;
        $registerConfig = (bool) ($config['register'] ?? false);

        if ($loginConfig !== false) {
            $loginConfig = (array) $loginConfig;
            self::wireLogin($container, $verifier, $tracker, $siteKey, $loginConfig);
        }

        if ($registerConfig) {
            self::wireRegister($container, $verifier, $siteKey);
        }
    }

    /**
     * Wire reCAPTCHA into the login form.
     *
     * Registers form slots and wraps the before_login hook to verify the token.
     * In x_failed mode, the captcha is shown and verified only after the threshold
     * of consecutive failed attempts is reached within the current session.
     *
     * @param ContainerInterface        $container
     * @param RecaptchaVerifier         $verifier
     * @param FailedLoginTracker        $tracker
     * @param string                    $siteKey
     * @param array<string, mixed>      $loginConfig
     * @return void
     */
    private static function wireLogin(
        ContainerInterface $container,
        RecaptchaVerifier $verifier,
        FailedLoginTracker $tracker,
        string $siteKey,
        array $loginConfig,
    ): void {
        $mode      = (string) ($loginConfig['mode'] ?? 'always');
        $threshold = (int)    ($loginConfig['threshold'] ?? 3);
        $always    = ($mode !== 'x_failed');

        $formSlots = $container->get(FormSlotRegistry::class);

        $formSlots->register('login', 'form_fields', static function () use ($siteKey, $always, $threshold, $tracker): string {
            if (!$always && $tracker->count() < $threshold) {
                return '';
            }
            return '<div class="g-recaptcha mb-3 js-dk-recaptcha"'
                . ' data-sitekey="' . \htmlspecialchars($siteKey, \ENT_QUOTES) . '"'
                . ' data-callback="dashboardKitCaptchaSuccess"'
                . ' data-expired-callback="dashboardKitCaptchaExpired"></div>';
        });

        $formSlots->register('login', 'scripts', static function () use ($always, $threshold, $tracker): string {
            if (!$always && $tracker->count() < $threshold) {
                return '';
            }
            return self::captchaScripts();
        });

        $hooks = $container->get(HookRegistry::class);

        // Increment counter on each failed login attempt.
        $hooks->on('login_failed', static function () use ($tracker): void {
            $tracker->increment();
        });

        // Reset counter on successful login.
        $hooks->on('login', static function () use ($tracker): void {
            $tracker->reset();
        });

        // Wrap before_login: chain existing callable, then verify reCAPTCHA when required.
        // PHP-DI treats plain closures set via set() as factory definitions, so the callable
        // is wrapped in an outer factory that returns the actual hook callable as a value.
        $ipResolver = $container->has(\rafalmasiarek\RealIpResolver::class)
            ? $container->get(\rafalmasiarek\RealIpResolver::class)
            : null;

        $existing = $container->get('auth.before_login');
        $container->set('auth.before_login', static fn() => static function (ServerRequestInterface $request) use (
            $existing,
            $verifier,
            $tracker,
            $always,
            $threshold,
            $ipResolver,
        ): ?string {
            if ($existing !== null) {
                $blockReason = $existing($request);
                if ($blockReason !== null) {
                    return $blockReason;
                }
            }

            $required = $always || $tracker->count() >= $threshold;
            if (!$required) {
                return null;
            }

            $body  = (array) $request->getParsedBody();
            $token = (string) ($body['g-recaptcha-response'] ?? '');
            $ip    = $ipResolver !== null
                ? ($ipResolver->getIp() ?: '')
                : (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

            if (!$verifier->verify($token, $ip)) {
                return 'Please complete the CAPTCHA verification.';
            }

            return null;
        });
    }

    /**
     * Returns the reCAPTCHA API script tag and the submit-button blocker.
     *
     * The blocker disables the form's submit button immediately on page load and
     * re-enables it only after the reCAPTCHA challenge is solved. The button is
     * disabled again when the token expires.
     *
     * @return string HTML to inject into the scripts slot.
     */
    private static function captchaScripts(): string
    {
        $script = self::minifyInlineJs(<<<'JS'
(function () {
    var el     = document.querySelector('.js-dk-recaptcha');
    var form   = el ? el.closest('form') : null;
    var btn    = form ? form.querySelector('[type="submit"]') : null;
    var solved = false;

    if (btn) btn.disabled = true;

    var hint = null;
    if (el) {
        hint = document.createElement('div');
        hint.className = 'text-danger small mt-1';
        hint.style.display = 'none';
        hint.textContent = 'Please complete the CAPTCHA.';
        el.insertAdjacentElement('afterend', hint);
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!solved) {
                e.preventDefault();
                if (el)   el.style.outline = '2px solid #dc3545';
                if (hint) hint.style.display = '';
            }
        });
    }

    window.dashboardKitCaptchaSuccess = function () {
        solved = true;
        if (btn)  btn.disabled = false;
        if (el)   el.style.outline = '';
        if (hint) hint.style.display = 'none';
    };
    window.dashboardKitCaptchaExpired = function () {
        solved = false;
        if (btn)  btn.disabled = true;
        if (el)   el.style.outline = '2px solid #dc3545';
        if (hint) hint.style.display = '';
    };
}());
JS);

        return '<script src="https://www.google.com/recaptcha/api.js" async defer></script>'
            . '<script>' . $script . '</script>';
    }

    /**
     * Collapses a multi-line JavaScript snippet to a single line.
     *
     * Trims each line, collapses internal whitespace, drops blank lines.
     * Safe only for simple scripts — no regex literals, no template strings.
     *
     * @param  string $js Multi-line JS source.
     * @return string Single-line output.
     */
    private static function minifyInlineJs(string $js): string
    {
        $out = [];
        foreach (\explode("\n", $js) as $line) {
            $line = \preg_replace('/\s+/', ' ', \trim($line));
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return \implode(' ', $out);
    }

    /**
     * Wire reCAPTCHA into the register form.
     *
     * Captcha is always shown on the register form when this method is called.
     *
     * @param ContainerInterface $container
     * @param RecaptchaVerifier  $verifier
     * @param string             $siteKey
     * @return void
     */
    private static function wireRegister(
        ContainerInterface $container,
        RecaptchaVerifier $verifier,
        string $siteKey,
    ): void {
        $formSlots = $container->get(FormSlotRegistry::class);

        $formSlots->register('register', 'form_fields',
            '<div class="g-recaptcha mb-3 js-dk-recaptcha"'
            . ' data-sitekey="' . \htmlspecialchars($siteKey, \ENT_QUOTES) . '"'
            . ' data-callback="dashboardKitCaptchaSuccess"'
            . ' data-expired-callback="dashboardKitCaptchaExpired"></div>'
        );

        $formSlots->register('register', 'scripts', self::captchaScripts());

        $ipResolver = $container->has(\rafalmasiarek\RealIpResolver::class)
            ? $container->get(\rafalmasiarek\RealIpResolver::class)
            : null;

        $existing = $container->get('auth.before_register');
        $container->set('auth.before_register', static fn() => static function (ServerRequestInterface $request) use (
            $existing,
            $verifier,
            $ipResolver,
        ): ?string {
            if ($existing !== null) {
                $blockReason = $existing($request);
                if ($blockReason !== null) {
                    return $blockReason;
                }
            }

            $body  = (array) $request->getParsedBody();
            $token = (string) ($body['g-recaptcha-response'] ?? '');
            $ip    = $ipResolver !== null
                ? ($ipResolver->getIp() ?: '')
                : (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

            if (!$verifier->verify($token, $ip)) {
                return 'Please complete the CAPTCHA verification.';
            }

            return null;
        });
    }
}
