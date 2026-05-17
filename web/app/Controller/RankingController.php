<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\RankingRepository;

/** The character leaderboard page. */
final class RankingController extends Controller
{
    public function __construct(View $view, private readonly RankingRepository $rankings)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        return $this->html('rankings', [
            'title' => 'Rankings',
            'players' => $this->rankings->topByLevel(),
        ]);
    }
}
