# **DEPRECATED** dashboard-kit-addon-recaptcha
### **THIS REPOSITORY IS NO LONGER MAINTAINED**
[![Packagist Version](https://img.shields.io/packagist/v/rafalmasiarek/dashboard-kit-addon-recaptcha)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-recaptcha)
[![Total Downloads](https://img.shields.io/packagist/dt/rafalmasiarek/dashboard-kit-addon-recaptcha)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-recaptcha)
[![PHP](https://img.shields.io/packagist/php-v/rafalmasiarek/dashboard-kit-addon-recaptcha)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-recaptcha)
[![License](https://img.shields.io/badge/license-BUSL--1.1-red)](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/LICENSE)

**This repository is no longer developed.**

Superseded by [rafalmasiarek/dashboard-kit-addon-captcha](https://github.com/rafalmasiarek/php-dashboard-kit-addon-captcha), which covers reCAPTCHA (v2/v3), Cloudflare Turnstile, and hCaptcha through one generalized integration. Please migrate to that package — it is the actively developed replacement.


Google reCAPTCHA v2 addon for [rafalmasiarek/dashboard-kit](https://github.com/rafalmasiarek/php-dashboard-kit).

Injects the captcha widget into login and register forms via the `FormSlotRegistry` extension point. Server-side verification uses the `before_login` / `before_register` hook chain.



## License
![License: BUSL-1.1](https://img.shields.io/badge/license-BUSL--1.1-red)

Business Source License 1.1.
Source available; commercial use restricted until 2099-01-01.
For alternative licensing, contact: https://masiarek.pl/contact/?af_subject=Commercial+license+%E2%80%94+dashboard-kit-addon-recaptcha&af_message=Hello%2C+I+am+interested+in+a+commercial+license+for+dashboard-kit-addon-recaptcha.
See [LICENSE](LICENSE) for full details.
## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [github](https://github.com/rafalmasiarek)


## Requirements

- PHP 8.2+
- `rafalmasiarek/dashboard-kit: *`
- Google reCAPTCHA v2 site key and secret key

## Installation

```bash
composer require rafalmasiarek/dashboard-kit-addon-recaptcha
```

## Usage

### Via `Dashboard::create()` config (recommended)

Pass config under the `recaptcha` key in `Dashboard::create()`. No arguments needed at the call site:

```php
$dashboard = Dashboard::create(__DIR__ . '/../', [
    'recaptcha' => [
        'site_key'   => $_ENV['RECAPTCHA_SITE_KEY'],
        'secret_key' => $_ENV['RECAPTCHA_SECRET_KEY'],
        'login'      => ['mode' => 'x_failed', 'threshold' => 3],
        'register'   => true,
    ],
]);

RecaptchaAddon::register($dashboard->getApp(), $dashboard->getContainer());
```

### Inline config

Pass config directly to `register()`. Takes precedence over `app.config['recaptcha']` when both are set.

```php
RecaptchaAddon::register($dashboard->getApp(), $dashboard->getContainer(), [
    'site_key'   => 'your-site-key',
    'secret_key' => 'your-secret-key',
    'login'      => ['mode' => 'x_failed', 'threshold' => 3],
    'register'   => true,
]);
```

## Configuration

| Key | Type | Description |
|-----|------|-------------|
| `site_key` | string | reCAPTCHA v2 site key (required) |
| `secret_key` | string | reCAPTCHA v2 secret key (required) |
| `login` | array\|false | Login form config. `false` disables captcha on login. |
| `login.mode` | `'always'`\|`'x_failed'` | `always` — show on every login attempt. `x_failed` — show only after N consecutive failures. |
| `login.threshold` | int | Number of failures before captcha appears (`x_failed` mode). Default: `3`. |
| `register` | bool | Whether to show captcha on the register form. Default: `false`. |

## Modes

### `always`

The captcha widget is rendered on every visit to `/login`. The token is verified on every POST.

```php
'login' => ['mode' => 'always'],
```

### `x_failed`

The captcha is hidden until the user has failed `threshold` consecutive login attempts within the same browser session. After the threshold is reached, the captcha appears and its token is verified on every subsequent attempt. A successful login resets the counter.

```php
'login' => ['mode' => 'x_failed', 'threshold' => 3],
```

## Frontend submit blocker

When the captcha widget is rendered, the form's submit button is disabled immediately on page load — before the reCAPTCHA API script even loads. The button is re-enabled only after the challenge is solved and disabled again if the token expires.

This prevents the backend from being hit with unanswered CAPTCHA requests. Server-side verification via `before_login` / `before_register` remains the authoritative check — the frontend blocker is a UX layer only.

In `x_failed` mode the blocker appears together with the widget, so the button is not affected until the threshold is reached.

## Testing

Google provides permanent test keys that always pass verification:

| Key | Value |
|-----|-------|
| Site key | `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI` |
| Secret key | `6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe` |

The captcha widget renders with a "This is a test" watermark when using test keys.


## Index

### Classes

- [`FailedLoginTracker`](#failedlogintracker) — `rafalmasiarek\DashboardKitRecaptcha`
- [`RecaptchaAddon`](#recaptchaaddon) — `rafalmasiarek\DashboardKitRecaptcha`
- [`RecaptchaVerifier`](#recaptchaverifier) — `rafalmasiarek\DashboardKitRecaptcha`

## `FailedLoginTracker`

*final class* — `rafalmasiarek\DashboardKitRecaptcha\FailedLoginTracker` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/FailedLoginTracker.php#L20)

Tracks failed login attempts in the PHP session for the current browser session. Used by RecaptchaAddon to determine whether the reCAPTCHA widget should be displayed and verified when mode is set to 'x_failed'. The session must already be started before any method is called. AuthKit's PhpSessionTransport::initialize() guarantees this because Auth::isLoggedIn() — invoked at the top of AuthController::login() — triggers the transport initialisation.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`increment()`](#failedlogintracker-increment) | public | `int` | Increment the failure counter for the current session. |
| [`count()`](#failedlogintracker-count) | public | `int` | Return the current failure count for the session. |
| [`reset()`](#failedlogintracker-reset) | public | `void` | Reset the failure counter, e.g. on successful login. |

<details>
<summary><code>increment()</code></summary>

#### `FailedLoginTracker increment()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/FailedLoginTracker.php#L29)

```php
public function increment(): int
```

Increment the failure counter for the current session.

**Returns:** `int` — Updated counter value.

</details>

<details>
<summary><code>count()</code></summary>

#### `FailedLoginTracker count()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/FailedLoginTracker.php#L41)

```php
public function count(): int
```

Return the current failure count for the session.

**Returns:** `int`

</details>

<details>
<summary><code>reset()</code></summary>

#### `FailedLoginTracker reset()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/FailedLoginTracker.php#L51)

```php
public function reset(): void
```

Reset the failure counter, e.g. on successful login.

</details>

## `RecaptchaAddon`

*final class* — `rafalmasiarek\DashboardKitRecaptcha\RecaptchaAddon` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L22)

Wires Google reCAPTCHA (v2 checkbox or v3 invisible) into dashboard-kit login and register forms.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#recaptchaaddon-register) | public static | `void` | Register the reCAPTCHA addon with the given application and container. |
| [`wireLogin()`](#recaptchaaddon-wirelogin) | private static | `void` | Wire reCAPTCHA into the login form. Registers form slots and wraps the before_login hook to verify the token. In x_failed mode (v2 only), the captcha is shown and verified only after the threshold of consecutive failed attempts is reached within the current session. v3 is invisible and, having no widget to show/hide, always runs. |
| [`scripts()`](#recaptchaaddon-scripts) | private static | `string` | Returns the reCAPTCHA API script tag and the submit-button blocker. The blocker disables the form's submit button immediately on page load and re-enables it only after the reCAPTCHA challenge is solved. The button is disabled again when the token expires. |
| [`widgetMarkup()`](#recaptchaaddon-widgetmarkup) | private static | `string` | Widget markup for the form_fields slot. v2 renders the visible checkbox div; v3 has no widget — only a hidden field the script fills in right before submit. |
| [`v2Scripts()`](#recaptchaaddon-v2scripts) | private static | `string` | v2 checkbox scripts: loads the API and blocks submit until solved. |
| [`v3Scripts()`](#recaptchaaddon-v3scripts) | private static | `string` | v3 invisible scripts: intercepts submit, fetches a token, fills the hidden field, then resubmits via the raw DOM submit() (which, unlike requestSubmit(), does not re-fire the 'submit' event — no loop guard needed). |
| [`minifyInlineJs()`](#recaptchaaddon-minifyinlinejs) | private static | `string` | Collapses a multi-line JavaScript snippet to a single line. Trims each line, collapses internal whitespace, drops blank lines. Safe only for simple scripts — no regex literals, no template strings. |
| [`wireRegister()`](#recaptchaaddon-wireregister) | private static | `void` | Wire reCAPTCHA into the register form. Captcha is always shown on the register form when this method is called. |

<details>
<summary><code>register()</code></summary>

#### `RecaptchaAddon register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L36)

```php
public static function register(App $app, ContainerInterface $container, array $config): void
```

Register the reCAPTCHA addon with the given application and container.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | Slim application instance. |
| `$container` | `ContainerInterface` | PHP-DI container. |
| `$config` | `array<string, mixed>` | Addon configuration: site_key, secret_key, version ('v2'\|'v3', default 'v2'), login, register. login/register may be `true`/`false` or an array with 'mode'/'threshold' (v2, login only), 'min_score' and 'action' (v3 only, both optional). |

</details>

<details>
<summary><code>wireLogin()</code></summary>

#### `RecaptchaAddon wireLogin()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L96)

```php
private static function wireLogin(ContainerInterface $container, RecaptchaVerifier $verifier, FailedLoginTracker $tracker, string $siteKey, string $version, array $loginConfig): void
```

Wire reCAPTCHA into the login form. Registers form slots and wraps the before_login hook to verify the token. In x_failed mode (v2 only), the captcha is shown and verified only after the threshold of consecutive failed attempts is reached within the current session. v3 is invisible and, having no widget to show/hide, always runs.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | — |
| `$verifier` | [`RecaptchaVerifier`](#recaptchaverifier) | — |
| `$tracker` | [`FailedLoginTracker`](#failedlogintracker) | — |
| `$siteKey` | `string` | — |
| `$version` | `string` | 'v2' or 'v3'. |
| `$loginConfig` | `array<string, mixed>` | v2: 'mode', 'threshold'. v3: 'min_score', 'action'. |

</details>

<details>
<summary><code>scripts()</code></summary>

#### `RecaptchaAddon scripts()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L194)

```php
private static function scripts(string $version, string $siteKey, string $action): string
```

Returns the reCAPTCHA API script tag and the submit-button blocker. The blocker disables the form's submit button immediately on page load and re-enables it only after the reCAPTCHA challenge is solved. The button is disabled again when the token expires.

| Param | Type | Description |
|---|---|---|
| `$version` | `string` | 'v2' or 'v3'. |
| `$siteKey` | `string` | Site key (only needed by v3, embedded in the execute() call). |
| `$action` | `string` | v3 only: action name passed to grecaptcha.execute(). |

**Returns:** `string` — HTML to inject into the scripts slot.

</details>

<details>
<summary><code>widgetMarkup()</code></summary>

#### `RecaptchaAddon widgetMarkup()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L210)

```php
private static function widgetMarkup(string $version, string $siteKey, string $action): string
```

Widget markup for the form_fields slot. v2 renders the visible checkbox div; v3 has no widget — only a hidden field the script fills in right before submit.

| Param | Type | Description |
|---|---|---|
| `$version` | `string` | 'v2' or 'v3'. |
| `$siteKey` | `string` | Site key (only used by the v2 checkbox's data-sitekey). |
| `$action` | `string` | Unused for v2; kept for a uniform call signature. |

**Returns:** `string` — HTML to inject into the form_fields slot.

</details>

<details>
<summary><code>v2Scripts()</code></summary>

#### `RecaptchaAddon v2Scripts()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L227)

```php
private static function v2Scripts(): string
```

v2 checkbox scripts: loads the API and blocks submit until solved.

**Returns:** `string` — HTML to inject into the scripts slot.

</details>

<details>
<summary><code>v3Scripts()</code></summary>

#### `RecaptchaAddon v3Scripts()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L285)

```php
private static function v3Scripts(string $siteKey, string $action): string
```

v3 invisible scripts: intercepts submit, fetches a token, fills the hidden field, then resubmits via the raw DOM submit() (which, unlike requestSubmit(), does not re-fire the 'submit' event — no loop guard needed).

| Param | Type | Description |
|---|---|---|
| `$siteKey` | `string` | Site key passed to the render= API and to execute(). |
| `$action` | `string` | Action name passed to grecaptcha.execute(). |

**Returns:** `string` — HTML to inject into the scripts slot.

</details>

<details>
<summary><code>minifyInlineJs()</code></summary>

#### `RecaptchaAddon minifyInlineJs()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L321)

```php
private static function minifyInlineJs(string $js): string
```

Collapses a multi-line JavaScript snippet to a single line. Trims each line, collapses internal whitespace, drops blank lines. Safe only for simple scripts — no regex literals, no template strings.

| Param | Type | Description |
|---|---|---|
| `$js` | `string` | Multi-line JS source. |

**Returns:** `string` — Single-line output.

</details>

<details>
<summary><code>wireRegister()</code></summary>

#### `RecaptchaAddon wireRegister()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaAddon.php#L345)

```php
private static function wireRegister(ContainerInterface $container, RecaptchaVerifier $verifier, string $siteKey, string $version, array $registerConfig): void
```

Wire reCAPTCHA into the register form. Captcha is always shown on the register form when this method is called.

| Param | Type | Description |
|---|---|---|
| `$container` | `ContainerInterface` | — |
| `$verifier` | [`RecaptchaVerifier`](#recaptchaverifier) | — |
| `$siteKey` | `string` | — |
| `$version` | `string` | 'v2' or 'v3'. |
| `$registerConfig` | `array<string, mixed>` | v3 only: 'min_score', 'action'. |

</details>

## `RecaptchaVerifier`

*final class* — `rafalmasiarek\DashboardKitRecaptcha\RecaptchaVerifier` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaVerifier.php#L14)

Verifies a Google reCAPTCHA v2 or v3 response token against the siteverify API.

**Depends on:** `HttpClientInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#recaptchaverifier-__construct) | public | — | — |
| [`verify()`](#recaptchaverifier-verify) | public | `bool` | Verify a g-recaptcha-response token from a form submission. $minScore and $expectedAction only apply to v3 tokens — siteverify's response includes 'score' and 'action' for those; both checks are skipped (treated as passing) when the corresponding field is absent, so v2 tokens are unaffected. |

<details>
<summary><code>__construct()</code></summary>

#### `RecaptchaVerifier __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaVerifier.php#L22)

```php
public function __construct(HttpClientInterface $http, string $secretKey)
```

| Param | Type | Description |
|---|---|---|
| `$http` | `HttpClientInterface` | Client used to call the siteverify API. |
| `$secretKey` | `string` | reCAPTCHA secret key from the Google console. |

</details>

<details>
<summary><code>verify()</code></summary>

#### `RecaptchaVerifier verify()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha/blob/main/src/RecaptchaVerifier.php#L42)

```php
public function verify(string $token, string $remoteIp, ?float $minScore, ?string $expectedAction): bool
```

Verify a g-recaptcha-response token from a form submission. $minScore and $expectedAction only apply to v3 tokens — siteverify's response includes 'score' and 'action' for those; both checks are skipped (treated as passing) when the corresponding field is absent, so v2 tokens are unaffected.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | Value of the g-recaptcha-response POST field. |
| `$remoteIp` | `string` | Client IP address for additional validation. |
| `$minScore` | `float\|null` | v3 only: minimum accepted score. Null = do not check. |
| `$expectedAction` | `string\|null` | v3 only: expected 'action' value. Null = do not check. |

**Returns:** `bool` — True when Google confirms the token is valid.

</details>




<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-dashboard-kit-addon-recaptcha
