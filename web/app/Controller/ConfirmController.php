<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AccountService;
use GfServer\App\FormController;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\TokenService;

/** Handles the email-confirmation link. */
final class ConfirmController extends FormController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TokenService $tokens,
        private readonly AccountService $accounts,
    ) {
        parent::__construct($view, $session);
    }

    public function confirm(): Response
    {
        $token = (string) ($_GET['token'] ?? '');
        $accountId = $token !== '' ? $this->tokens->consume($token, 'email_verify') : null;

        if ($accountId === null) {
            return $this->page('confirm', ['title' => 'Confirmation', 'ok' => false], 400);
        }

        $this->accounts->confirmEmail($accountId);

        return $this->page('confirm', ['title' => 'Confirmation', 'ok' => true]);
    }
}
