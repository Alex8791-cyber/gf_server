<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\Auth;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\RateLimiter;

/** Portal login and logout. */
final class LoginController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly AccountService $accounts,
        private readonly Auth $auth,
        private readonly RateLimiter $limiter,
    ) {
        parent::__construct($view, $session);
    }

    public function showForm(): Response
    {
        return $this->page('login', ['title' => 'Log in', 'error' => null]);
    }

    public function submit(): Response
    {
        if (!$this->csrfValid()) {
            return $this->page('login', ['title' => 'Log in', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($this->limiter->tooManyFailures($ip, 5, 900)) {
            return $this->page('login', ['title' => 'Log in', 'error' => 'Too many failed attempts. Please try again later.'], 429);
        }

        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $accountId = $this->accounts->authenticate($username, $password);

        if ($accountId === null) {
            $this->limiter->record($ip, false);

            return $this->page('login', ['title' => 'Log in', 'error' => 'Wrong username or password.'], 401);
        }

        $this->limiter->record($ip, true);
        $this->auth->login($accountId, strtolower(trim($username)));
        $this->flash('Welcome back.');

        return $this->redirect('/account');
    }

    public function logout(): Response
    {
        if ($this->csrfValid()) {
            $this->auth->logout();
        }

        return $this->redirect('/');
    }
}
