<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\NewsRepository;

/** Lists news and shows a single news item (via ?id=). */
final class NewsController extends Controller
{
    public function __construct(View $view, private readonly NewsRepository $news)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

        if ($id !== null) {
            $item = $this->news->find($id);
            if ($item === null) {
                return $this->html('news_show', ['title' => 'News', 'item' => null], 404);
            }

            return $this->html('news_show', ['title' => $item['title'], 'item' => $item]);
        }

        return $this->html('news_list', [
            'title' => 'News',
            'items' => $this->news->published(),
        ]);
    }
}
