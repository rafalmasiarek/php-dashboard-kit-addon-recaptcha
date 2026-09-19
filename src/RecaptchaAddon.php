<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use rafalmasiarek\DashboardKit\Dns\SystemDnsResolver;
use rafalmasiarek\DashboardKit\Extension\FormSlotRegistry;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Http\CurlHttpClient;
use rafalmasiarek\DashboardKit\Http\HttpClientInterface;
use Slim\App;

/**
 * Wires Google reCAPTCHA (v2 checkbox or v3 invisible) into dashboard-kit
 * login and register forms.
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
     * @param array<string, mixed> $config    Addon configuration: site_key, secret_key,
     *                                         version ('v2'|'v3', default 'v2'), login, register.
     *                                         login/register may be `true`/`false` or an array
     *                                         with 'mode'/'threshold' (v2, login only), 'min_score'
     *                                         and 'action' (v3 only, both optional).
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
        $version   = (string) ($config['version']    ?? 'v2') === 'v3' ? 'v3' : 'v2';

        if ($siteKey === '' || $secretKey === '') {
            throw new \InvalidArgumentException(
                'RecaptchaAddon requires both site_key and secret_key to be set.'
            );
        }

        $http = $container->has(HttpClientInterface::class)
            ? $container->get(HttpClientInterface::class)
            : new CurlHttpClient(new SystemDnsResolver());

        $verifier = new RecaptchaVerifier($http, $secretKey);
        $tracker  = new FailedLoginTracker();

        $loginConfig    = $config['login']    ?? false;
        $registerConfig = $config['register'] ?? false;

        if ($loginConfig !== false) {
            $loginConfig = $loginConfig === true ? [] : (array) $loginConfig;
            self::wireLogin($container, $verifier, $tracker, $siteKey, $version, $loginConfig);
        }

        if ($registerConfig !== false) {
            $registerConfig = $registerConfig === true ? [] : (array) $registerConfig;
            self::wireRegister($container, $verifier, $siteKey, $version, $registerConfig);
        }
    }

    /**
     * Wire reCAPTCHA into the login form.
     *
     * Registers form slots and wraps the before_login hook to verify the token.
     * In x_failed mode (v2 only), the captcha is shown and verified only after
     * the threshold of consecutive failed attempts is reached within the
     * current session. v3 is invisible and, having no widget to show/hide,
     * always runs.
     *
     * @param ContainerInterface   $container
     * @param RecaptchaVerifier    $verifier
     * @param FailedLoginTracker   $tracker
     * @param string               $siteKey
     * @param string               $version     'v2' or 'v3'.
     * @param array<string, mixed> $loginConfig v2: 'mode', 'threshold'. v3: 'min_score', 'action'.
     * @return void
     */
    private static function wireLogin(
        ContainerInterface $container,
        RecaptchaVerifier $verifier,
        FailedLoginTracker $tracker,
        string $siteKey,
        string $version,
        array $loginConfig,
    ): void {
        $mode      = (string) ($loginConfig['mode'] ?? 'always');
        $threshold = (int)    ($loginConfig['threshold'] ?? 3);
        $always    = ($version === 'v3') || ($mode !== 'x_failed');
        $minScore  = isset($loginConfig['min_score']) ? (float) $loginConfig['min_score'] : null;
        $action    = (string) ($loginConfig['action'] ?? 'login');

        $formSlots = $container->get(FormSlotRegistry::class);

        $formSlots->register('login', 'form_fields', static function () use ($siteKey, $version, $action, $always, $threshold, $tracker): string {
            if (!$always && $tracker->count() < $threshold) {
                return '';
            }
            return self::widgetMarkup($version, $siteKey, $action);
        });

        $formSlots->register('login', 'scripts', static function () use ($siteKey, $version, $action, $always, $threshold, $tracker): string {
            if (!$always && $tracker->count() < $threshold) {
                return '';
            }
            return self::scripts($version, $siteKey, $action);
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
            $minScore,
            $action,
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

            if (!$verifier->verify($token, $ip, $minScore, $minScore !== null ? $action : null)) {
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
     * @param  string $version 'v2' or 'v3'.
     * @param  string $siteKey Site key (only needed by v3, embedded in the execute() call).
     * @param  string $action  v3 only: action name passed to grecaptcha.execute().
     * @return string          HTML to inject into the scripts slot.
     */
    private static function scripts(string $version, string $siteKey, string $action): string
    {
        return $version === 'v3' ? self::v3Scripts($siteKey, $action) : self::v2Scripts();
    }

    /**
     * Widget markup for the form_fields slot.
     *
     * v2 renders the visible checkbox div; v3 has no widget — only a hidden
     * field the script fills in right before submit.
     *
     * @param  string $version 'v2' or 'v3'.
     * @param  string $siteKey Site key (only used by the v2 checkbox's data-sitekey).
     * @param  string $action  Unused for v2; kept for a uniform call signature.
     * @return string          HTML to inject into the form_fields slot.
     */
    private static function widgetMarkup(string $version, string $siteKey, string $action): string
    {
        if ($version === 'v3') {
            return '<input type="hidden" name="g-recaptcha-response" class="js-dk-recaptcha-v3">';
        }

        return '<div class="g-recaptcha mb-3 js-dk-recaptcha"'
            . ' data-sitekey="' . \htmlspecialchars($siteKey, \ENT_QUOTES) . '"'
            . ' data-callback="dashboardKitCaptchaSuccess"'
            . ' data-expired-callback="dashboardKitCaptchaExpired"></div>';
    }

    /**
     * v2 checkbox scripts: loads the API and blocks submit until solved.
     *
     * @return string HTML to inject into the scripts slot.
     */
    private static function v2Scripts(): string
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
     * v3 invisible scripts: intercepts submit, fetches a token, fills the
     * hidden field, then resubmits via the raw DOM submit() (which, unlike
     * requestSubmit(), does not re-fire the 'submit' event — no loop guard needed).
     *
     * @param  string $siteKey Site key passed to the render= API and to execute().
     * @param  string $action  Action name passed to grecaptcha.execute().
     * @return string          HTML to inject into the scripts slot.
     */
    private static function v3Scripts(string $siteKey, string $action): string
    {
        $siteKeyJs = \json_encode($siteKey, \JSON_UNESCAPED_SLASHES);
        $actionJs  = \json_encode($action, \JSON_UNESCAPED_SLASHES);

        $script = self::minifyInlineJs(<<<JS
(function () {
    var el   = document.querySelector('.js-dk-recaptcha-v3');
    var form = el ? el.closest('form') : null;
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        grecaptcha.ready(function () {
            grecaptcha.execute({$siteKeyJs}, { action: {$actionJs} }).then(function (token) {
                el.value = token;
                form.submit();
            });
        });
    });
}());
JS);

        return '<script src="https://www.google.com/recaptcha/api.js?render=' . \rawurlencode($siteKey) . '"></script>'
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
     * @param ContainerInterface   $container
     * @param RecaptchaVerifier    $verifier
     * @param string               $siteKey
     * @param string               $version        'v2' or 'v3'.
     * @param array<string, mixed> $registerConfig v3 only: 'min_score', 'action'.
     * @return void
     */
    private static function wireRegister(
        ContainerInterface $container,
        RecaptchaVerifier $verifier,
        string $siteKey,
        string $version,
        array $registerConfig,
    ): void {
        $minScore = isset($registerConfig['min_score']) ? (float) $registerConfig['min_score'] : null;
        $action   = (string) ($registerConfig['action'] ?? 'register');

        $formSlots = $container->get(FormSlotRegistry::class);

        $formSlots->register('register', 'form_fields', self::widgetMarkup($version, $siteKey, $action));
        $formSlots->register('register', 'scripts', self::scripts($version, $siteKey, $action));

        $ipResolver = $container->has(\rafalmasiarek\RealIpResolver::class)
            ? $container->get(\rafalmasiarek\RealIpResolver::class)
            : null;

        $existing = $container->get('auth.before_register');
        $container->set('auth.before_register', static fn() => static function (ServerRequestInterface $request) use (
            $existing,
            $verifier,
            $minScore,
            $action,
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

            if (!$verifier->verify($token, $ip, $minScore, $minScore !== null ? $action : null)) {
                return 'Please complete the CAPTCHA verification.';
            }

            return null;
        });
    }
}
