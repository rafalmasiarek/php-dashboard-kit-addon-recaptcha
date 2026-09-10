# dashboard-kit-addon-recaptcha

Google reCAPTCHA v2 addon for [rafalmasiarek/dashboard-kit](https://github.com/rafalmasiarek/php-dashboard-kit).

Injects the captcha widget into login and register forms via the `FormSlotRegistry` extension point. Server-side verification uses the `before_login` / `before_register` hook chain.

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

## License

Business Source License 1.1 — see [LICENSE](LICENSE).
For alternative licensing, [contact us](https://masiarek.pl/contact/?af_subject=Commercial+license+%E2%80%94+dashboard-kit-addon-recaptcha&af_message=Hello%2C+I+am+interested+in+a+commercial+license+for+dashboard-kit-addon-recaptcha.).
