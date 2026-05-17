<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\Auth;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ValidationException;

/** The logged-in account home and password change. */
final class AccountController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Auth $auth,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function home(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }

        $web = $this->accounts->webAccount((int) $this->auth->accountId());

        return $this->page('account', [
            'title' => 'My account',
            'username' => (string) $this->auth->username(),
            'email' => $web['email'] ?? null,
            'verified' => $web['email_verified'] ?? false,
        ]);
    }

    public function showPasswordForm(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }

        return $this->page('password', ['title' => 'Change password', 'error' => null]);
    }

    public function changePassword(): Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }
        if (!$this->csrfValid()) {
            return $this->page('password', ['title' => 'Change password', 'error' => 'Invalid form token, please try again.'], 400);
        }

        $username = (string) $this->auth->username();
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');

        if ($this->accounts->authenticate($username, $current) === null) {
            return $this->page('password', ['title' => 'Change password', 'error' => 'Your current password is wrong.'], 401);
        }

        try {
            $this->accounts->changePassword($username, $new);
        } catch (ValidationException $e) {
            return $this->page('password', ['title' => 'Change password', 'error' => $e->getMessage()], 422);
        }

        $this->flash('Your password has been changed.');

        return $this->redirect('/account');
    }
}
