<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\AdminController;
use GfServer\App\Auth;
use GfServer\App\Response;
use GfServer\App\Session;
use GfServer\App\View;
use GfServer\NewsRepository;

/** Admin news management: list, create, edit, publish, delete. */
final class NewsAdminController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private readonly NewsRepository $news,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_news_list', [
            'title' => 'Manage news',
            'items' => $this->news->allForAdmin(),
        ]);
    }

    public function createForm(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        return $this->page('admin_news_form', [
            'title' => 'New post',
            'item' => null,
            'action' => '/admin/news/new',
            'error' => null,
        ]);
    }

    public function create(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->page('admin_news_form', ['title' => 'New post', 'item' => null, 'action' => '/admin/news/new', 'error' => 'Invalid form token.'], 400);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            return $this->page('admin_news_form', ['title' => 'New post', 'item' => null, 'action' => '/admin/news/new', 'error' => 'Title and body are required.'], 422);
        }

        $this->news->create($title, $body, $this->auth->accountId());
        $this->flash('News post created.');

        return $this->redirect('/admin/news');
    }

    public function editForm(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }

        $item = $this->news->findAny((int) ($_GET['id'] ?? 0));
        if ($item === null) {
            return $this->page('admin_news_form', ['title' => 'Edit post', 'item' => null, 'action' => '/admin/news/edit', 'error' => 'That news post does not exist.'], 404);
        }

        return $this->page('admin_news_form', [
            'title' => 'Edit post',
            'item' => $item,
            'action' => '/admin/news/edit',
            'error' => null,
        ]);
    }

    public function update(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if (!$this->csrfValid()) {
            return $this->redirect('/admin/news');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title !== '' && $body !== '' && $this->news->findAny($id) !== null) {
            $this->news->update($id, $title, $body);
            $this->flash('News post updated.');
        } else {
            $this->flash('Could not update that post.');
        }

        return $this->redirect('/admin/news');
    }

    public function togglePublish(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if ($this->csrfValid()) {
            $this->news->setPublished((int) ($_POST['id'] ?? 0), ($_POST['publish'] ?? '') === '1');
            $this->flash('News visibility updated.');
        }

        return $this->redirect('/admin/news');
    }

    public function delete(): Response
    {
        $deny = $this->denyUnlessAdmin();
        if ($deny !== null) {
            return $deny;
        }
        if ($this->csrfValid()) {
            $this->news->delete((int) ($_POST['id'] ?? 0));
            $this->flash('News post deleted.');
        }

        return $this->redirect('/admin/news');
    }
}
