<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Mailer;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ConflictException;
use GfServer\TokenService;
use GfServer\ValidationException;

/** Admin account management: password reset, lock/unlock, resend confirmation. */
final class AccountAdminController extends AdminController
{
    private const CONFIRM_TTL = 86400;

    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly AccountService $accounts,
        private readonly TokenService $tokens,
        private readonly Mailer $mailer,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_accounts', ['title' => 'Manage accounts']);
    }

    public function resetPassword(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        try {
            $this->accounts->changePassword(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
            );
            $this->flash('Password reset.');
        } catch (ConflictException | ValidationException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/accounts');
    }

    public function setLocked(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        try {
            $this->accounts->setAccountLocked(
                (string) ($_POST['username'] ?? ''),
                ($_POST['lock'] ?? '') === '1',
            );
            $this->flash('Account lock state updated.');
        } catch (ConflictException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/accounts');
    }

    public function resendConfirmation(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/accounts');
        }

        $username = (string) ($_POST['username'] ?? '');
        $accountId = $this->accounts->findByUsername($username);
        $web = $accountId !== null ? $this->accounts->webAccount($accountId) : null;

        if ($accountId === null || $web === null || $web['email'] === null) {
            $this->flash('No email address is on file for that account.');

            return $this->redirect('/admin/accounts');
        }

        $token = $this->tokens->create($accountId, 'email_verify', self::CONFIRM_TTL);
        $link = $this->portalUrl('/confirm?token=' . $token);
        $this->mailer->send(
            $web['email'],
            'Confirm your Grand Fantasia account',
            "Please confirm your account by opening this link:\n{$link}\n",
        );
        $this->flash('Confirmation email resent.');

        return $this->redirect('/admin/accounts');
    }

    /** Absolute portal URL built from the configured domain (not the Host header). */
    private function portalUrl(string $path): string
    {
        $domain = getenv('PORTAL_DOMAIN');
        $domain = ($domain !== false && $domain !== '') ? $domain : 'localhost';

        return 'https://' . $domain . $path;
    }
}
