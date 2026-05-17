<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\AdminService;
use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\ConflictException;
use GfServer\ValidationException;

/** Admin player management: GM privileges and renaming. */
final class PlayerAdminController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly AdminService $admin,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_players', ['title' => 'Manage players']);
    }

    public function setGm(): Response
    {
        return $this->run(function (): void {
            $this->admin->setGmPrivilege(
                (string) ($_POST['player'] ?? ''),
                ($_POST['grant'] ?? '') === '1',
            );
            $this->flash('GM privilege updated.');
        });
    }

    public function renamePlayer(): Response
    {
        return $this->run(function (): void {
            $this->admin->renamePlayer(
                (string) ($_POST['old_name'] ?? ''),
                (string) ($_POST['new_name'] ?? ''),
            );
            $this->flash('Player renamed.');
        });
    }

    public function renameSprite(): Response
    {
        return $this->run(function (): void {
            $this->admin->renameSprite(
                (string) ($_POST['player'] ?? ''),
                (string) ($_POST['sprite'] ?? ''),
            );
            $this->flash('Sprite renamed.');
        });
    }

    /**
     * Shared handling for the player POST actions: admin gate, CSRF check,
     * run $action, turn domain exceptions into a flash, redirect.
     */
    private function run(callable $action): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/players');
        }

        try {
            $action();
        } catch (ConflictException | ValidationException $e) {
            $this->flash('Error: ' . $e->getMessage());
        }

        return $this->redirect('/admin/players');
    }
}
