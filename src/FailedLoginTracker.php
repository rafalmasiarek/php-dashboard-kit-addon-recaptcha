<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitRecaptcha;

/**
 * Tracks failed login attempts in the PHP session for the current browser session.
 *
 * Used by RecaptchaAddon to determine whether the reCAPTCHA widget should be
 * displayed and verified when mode is set to 'x_failed'.
 *
 * The session must already be started before any method is called.
 * AuthKit's PhpSessionTransport::initialize() guarantees this because
 * Auth::isLoggedIn() — invoked at the top of AuthController::login() — triggers
 * the transport initialisation.
 *
 * @package rafalmasiarek\DashboardKitRecaptcha
 */
final class FailedLoginTracker
{
    private const SESSION_KEY = 'recaptcha.login_fails';

    /**
     * Increment the failure counter for the current session.
     *
     * @return int Updated counter value.
     */
    public function increment(): int
    {
        $count = $this->count() + 1;
        $_SESSION[self::SESSION_KEY] = $count;
        return $count;
    }

    /**
     * Return the current failure count for the session.
     *
     * @return int
     */
    public function count(): int
    {
        return (int) ($_SESSION[self::SESSION_KEY] ?? 0);
    }

    /**
     * Reset the failure counter, e.g. on successful login.
     *
     * @return void
     */
    public function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
