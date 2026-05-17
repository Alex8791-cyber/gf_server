<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ServerStatus;

/** The admin dashboard landing page. */
final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly ServerStatus $status,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_dashboard', [
            'title' => 'Admin',
            'accounts' => $this->status->accountCount(),
            'characters' => $this->status->characterCount(),
        ]);
    }
}
