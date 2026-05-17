<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Base for admin controllers. Every admin action calls denyUnlessAdmin()
 * first: it returns a redirect/forbidden Response when the visitor is not a
 * logged-in web_admin, or null when access is allowed.
 */
abstract class AdminController extends FormController
{
    public function __construct(View $view, Session $session, protected readonly Auth $auth)
    {
        parent::__construct($view, $session);
    }

    /** Returns a Response to send when access is denied, or null when allowed. */
    protected function denyUnlessAdmin(): ?Response
    {
        if (!$this->auth->isLoggedIn()) {
            return $this->redirect('/login');
        }
        if (!$this->auth->isAdmin()) {
            return $this->html('forbidden', ['title' => 'Forbidden'], 403);
        }

        return null;
    }
}
