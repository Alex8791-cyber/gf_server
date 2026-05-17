<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\FormController;
use GfServer\App\Mailer;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\RateLimiter;
use GfServer\TokenService;
use GfServer\ValidationException;

/** Password reset: request a link, then set a new password. */
final class PasswordResetController extends FormController
{
    private const RESET_TTL = 3600;

    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showRequestForm(): Response
    {
        return $this->page('forgot', ['title' => 'Reset password', 'error' => null]);
    }

    public function submitRequest(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('forgot', ['title' => 'Reset password', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 10, 3600)) {
            return $this->page('forgot', ['title' => 'Reset password', 'error' => 'Too many attempts. Please try again later.'], 429);
        }
        $this->limiter->record($ip, false);

        $email = (string) ($_POST['email'] ?? '');
        $accountId = $this->accounts->findByEmail($email);
        if ($accountId !== null) {
            $token = $this->tokens->create($accountId, 'password_reset', self::RESET_TTL);
            $link = $this->portalUrl('/reset?token=' . $token);
            $this->mailer->send(
                $email,
                'Reset your Grand Fantasia password',
                "A password reset was requested for your account.\n\n"
                . "Open this link to set a new password:\n{$link}\n\n"
                . "This link expires in one hour. If you did not request this, ignore this email.",
            );
        }

        // Always the same response, so the form cannot reveal which emails exist.
        return $this->page('forgot_done', ['title' => 'Reset password']);
    }

    public function showResetForm(): Response
    {
        return $this->page('reset', [
            'title' => 'Set a new password',
            'token' => (string) ($_GET['token'] ?? ''),
            'error' => null,
        ]);
    }

    public function submitReset(): Response
    {
        $token = (string) ($_POST['token'] ?? '');

        if (!$this->csrfValid()) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => $token, 'error' => 'Invalid form token, please try again.'], 400);
        }

        $accountId = $token !== '' ? $this->tokens->consume($token, 'password_reset') : null;
        if ($accountId === null) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => '', 'error' => 'That reset link is invalid or has expired.'], 400);
        }

        try {
            $this->accounts->changePasswordForAccount($accountId, (string) ($_POST['new'] ?? ''));
        } catch (ValidationException $e) {
            return $this->page('reset', ['title' => 'Set a new password', 'token' => $token, 'error' => $e->getMessage()], 422);
        }

        return $this->page('reset_done', ['title' => 'Password changed']);
    }

    /** Absolute portal URL built from the configured domain (not the Host header). */
    private function portalUrl(string $path): string
    {
        $domain = getenv('PORTAL_DOMAIN');
        $domain = ($domain !== false && $domain !== '') ? $domain : 'localhost';

        return 'https://' . $domain . $path;
    }
}
