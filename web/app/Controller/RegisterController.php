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
use GfServer\ConflictException;

/** Account registration with email confirmation. */
final class RegisterController extends FormController
{
    private const CONFIRM_TTL = 86400;

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

    public function showForm(): Response
    {
        return $this->page('register', ['title' => 'Register', 'error' => null]);
    }

    public function submit(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('register', ['title' => 'Register', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 10, 3600)) {
            return $this->page('register', ['title' => 'Register', 'error' => 'Too many attempts. Please try again later.'], 429);
        }

        $username = (string) ($_POST['username'] ?? '');
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $this->limiter->record($ip, false);

        try {
            $id = $this->accounts->register($username, $password, $email);
        } catch (ValidationException | ConflictException $e) {
            return $this->page('register', ['title' => 'Register', 'error' => $e->getMessage()], 422);
        }

        $token = $this->tokens->create($id, 'email_verify', self::CONFIRM_TTL);
        $link = $this->portalUrl('/confirm?token=' . $token);
        $this->mailer->send(
            $email,
            'Confirm your Grand Fantasia account',
            "Welcome to Grand Fantasia!\n\n"
            . "Confirm your account by opening this link:\n{$link}\n\n"
            . "If you did not register, ignore this email.",
        );

        return $this->page('register_done', ['title' => 'Check your email']);
    }

    /** Absolute portal URL built from the configured domain (not the Host header). */
    private function portalUrl(string $path): string
    {
        $domain = getenv('PORTAL_DOMAIN');
        $domain = ($domain !== false && $domain !== '') ? $domain : 'localhost';

        return 'https://' . $domain . $path;
    }
}
