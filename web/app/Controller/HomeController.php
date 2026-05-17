<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;

/** The portal landing page. */
final class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->html('home', ['title' => 'Home']);
    }
}
